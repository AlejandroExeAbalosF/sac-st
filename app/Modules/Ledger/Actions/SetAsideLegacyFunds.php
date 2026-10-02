<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Modules\Ledger\Enums\ChequeStatus;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\FundReceiptOrigin;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Ledger\Support\LegacyFundsLock;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aparta plata del saldo del sistema anterior para un dueño.
 *
 * ```text
 * Débito   LEGACY_FUNDS        deja de ser «del sistema anterior»
 * Crédito  BENEFICIARY_FUNDS   y pasa a ser de este dueño
 * ```
 *
 * **No mueve el dinero de lugar.** El efectivo sigue en el cajón, el
 * cheque en la cartera y el depósito directo en la cuenta: el arqueo no
 * cambia. Lo que cambia es de quién es, y por eso el asiento solo toca
 * cuentas de atribución —la base lo exige—.
 *
 * Deja la recepción de la que se va a asignar: el cheque de la apertura,
 * que ya es una, o una nueva `legacy` para el efectivo y el depósito
 * directo, que en la apertura entraron como un total. Ledger no sabe de
 * cuotas: el dueño viaja como los dos punteros sueltos de siempre
 * (`forInstallment`), y la asignación la escribe quien sí sabe.
 *
 * Va con la fecha de hoy: el hecho es apartar, y ocurre hoy. La fecha del
 * papel que lo respalda la guarda quien lo carga.
 */
final class SetAsideLegacyFunds
{
    public function __construct(
        private readonly PostJournalEntry $asentar,
        private readonly CashBalance $saldos,
        private readonly LegacyFundsLock $bloqueo,
    ) {}

    /**
     * @param  numeric-string  $amount
     * @param  FundReceipt|null  $cheque  El de la cartera de la apertura, si sale de un cheque.
     * @param  int|null  $bankAccountId  La cuenta, si sale de un depósito directo.
     * @return array{event: FinancialEvent, receipt: FundReceipt}
     *
     * @throws ValidationException
     */
    public function handle(
        int $cashBoxId,
        Currency $currency,
        PaymentMedium $medium,
        string $amount,
        int $haberId,
        int $installmentId,
        string $idempotencyKey,
        ?FundReceipt $cheque = null,
        ?int $bankAccountId = null,
        ?string $description = null,
        ?int $actorId = null,
    ): array {
        $importe = Decimal::scale($amount);

        if (Decimal::isNegative($importe) || Decimal::equals($importe, '0')) {
            throw ValidationException::withMessages([
                'amount' => 'Apartar tiene que ser por un importe mayor que cero.',
            ]);
        }

        return DB::transaction(function () use (
            $cashBoxId, $currency, $medium, $importe, $haberId, $installmentId,
            $idempotencyKey, $cheque, $bankAccountId, $description, $actorId,
        ): array {
            $this->bloqueo->acquire($cashBoxId, $currency);

            $this->assertSource($cashBoxId, $currency, $medium, $importe, $cheque, $bankAccountId);

            $pendiente = $this->saldos->of(LedgerAccount::LegacyFunds, $cashBoxId, $currency);

            if (Decimal::isNegative(Decimal::sub($pendiente, $importe))) {
                throw ValidationException::withMessages([
                    'amount' => sprintf(
                        'Del sistema anterior quedan sin asignar %s y esto es por %s.',
                        Decimal::format($pendiente),
                        Decimal::format($importe),
                    ),
                ]);
            }

            $hoy = BusinessDate::today();

            $evento = $this->asentar->handle(
                type: FinancialEventType::LegacyFundsAllocated,
                idempotencyKey: $idempotencyKey,
                lines: [
                    EntryLine::debit(LedgerAccount::LegacyFunds, $importe)
                        ->in($currency)
                        ->onCashBox($cashBoxId)
                        ->describedAs('Fondos del sistema anterior'),
                    EntryLine::credit(LedgerAccount::BeneficiaryFunds, $importe)
                        ->in($currency)
                        ->forInstallment($haberId, $installmentId)
                        ->onCashBox($cashBoxId),
                ],
                date: $hoy,
                cashBoxId: $cashBoxId,
                description: $description,
                actorId: $actorId,
            );

            if ($cheque !== null) {
                return ['event' => $evento, 'receipt' => $cheque];
            }

            $recepcion = FundReceipt::query()->firstOrCreate(
                ['financial_event_id' => $evento->id],
                [
                    'cash_box_id' => $cashBoxId,
                    'currency' => $currency->value,
                    'medium' => $medium,
                    'origin' => FundReceiptOrigin::Legacy,
                    'bank_account_id' => $medium === PaymentMedium::Bank ? $bankAccountId : null,
                    'amount' => $importe,
                    'received_date' => $hoy,
                    'received_by' => $actorId,
                    'notes' => $description,
                ],
            );

            return ['event' => $evento, 'receipt' => $recepcion];
        });
    }

    /**
     * De dónde sale, y que esté ahí.
     *
     * El cajón y la cuenta guardan plata de los dos circuitos, así que el
     * control es sobre el total que hay, no sobre la parte vieja: no hay
     * forma de saber qué billete es de cuál. Lo que sí se sabe con certeza
     * es que no se aparta lo que no está.
     *
     * @param  numeric-string  $amount
     *
     * @throws ValidationException
     */
    private function assertSource(
        int $cashBoxId,
        Currency $currency,
        PaymentMedium $medium,
        string $amount,
        ?FundReceipt $cheque,
        ?int $bankAccountId,
    ): void {
        if ($medium === PaymentMedium::Cheque) {
            if ($cheque === null
                || $cheque->origin !== FundReceiptOrigin::Opening
                || $cheque->medium !== PaymentMedium::Cheque
                || $cheque->cheque_status !== ChequeStatus::InCustody
                || $cheque->reversal_event_id !== null
                || $cheque->cash_box_id !== $cashBoxId
                || $cheque->currency !== $currency->value
            ) {
                throw ValidationException::withMessages([
                    'source' => 'El cheque tiene que ser uno de la cartera de la apertura, todavía en custodia.',
                ]);
            }

            return;
        }

        if ($medium === PaymentMedium::Bank && $bankAccountId === null) {
            throw ValidationException::withMessages([
                'bankAccountId' => 'Un depósito directo tiene que decir en qué cuenta está.',
            ]);
        }

        $cuenta = $medium === PaymentMedium::Bank ? LedgerAccount::BankAccount : LedgerAccount::CashOnHand;
        $disponible = $this->saldos->of($cuenta, $cashBoxId, $currency);

        if (Decimal::isNegative(Decimal::sub($disponible, $amount))) {
            throw ValidationException::withMessages([
                'amount' => sprintf(
                    'En «%s» hay %s y esto es por %s.',
                    $cuenta->label(),
                    Decimal::format($disponible),
                    Decimal::format($amount),
                ),
            ]);
        }
    }
}
