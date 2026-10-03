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
use App\Modules\Ledger\Support\UndetailedCheques;
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
 * que ya es una, o una nueva `legacy` para el efectivo, el depósito
 * directo y el cheque que se identifica recién ahora —los tres pueden haber
 * entrado en la apertura como un total, sin detalle—. Ledger no sabe de
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
        private readonly UndetailedCheques $sinDetallar,
    ) {}

    /**
     * @param  numeric-string  $amount
     * @param  FundReceipt|null  $cheque  El de la cartera de la apertura, si sale de un cheque.
     * @param  array{number: string, bank: string|null, issueDate: string|null}|null  $newCheque  Un cheque
     *                                                                                            que la
     *                                                                                            apertura
     *                                                                                            declaró
     *                                                                                            sin detalle.
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
        ?array $newCheque = null,
    ): array {
        $importe = Decimal::scale($amount);

        if (Decimal::isNegative($importe) || Decimal::equals($importe, '0')) {
            throw ValidationException::withMessages([
                'amount' => 'Apartar tiene que ser por un importe mayor que cero.',
            ]);
        }

        return DB::transaction(function () use (
            $cashBoxId, $currency, $medium, $importe, $haberId, $installmentId,
            $idempotencyKey, $cheque, $bankAccountId, $description, $actorId, $newCheque,
        ): array {
            $this->bloqueo->acquire($cashBoxId, $currency);

            $this->assertSource($cashBoxId, $currency, $medium, $importe, $cheque, $bankAccountId, $newCheque);

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
                    // El cheque identificado entra a la cartera con su papel,
                    // en custodia: se entrega o se deposita como cualquier otro.
                    'cheque_number' => $newCheque === null ? null : trim($newCheque['number']),
                    'cheque_bank' => $newCheque['bank'] ?? null,
                    'cheque_issue_date' => $newCheque['issueDate'] ?? null,
                    'cheque_status' => $newCheque === null ? null : ChequeStatus::InCustody,
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
     * @param  array{number: string, bank: string|null, issueDate: string|null}|null  $newCheque
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
        ?array $newCheque,
    ): void {
        if ($medium === PaymentMedium::Cheque && $cheque === null && $newCheque !== null) {
            $this->assertIdentifiable($cashBoxId, $currency, $amount, $newCheque);

            return;
        }

        if ($medium === PaymentMedium::Cheque) {
            /*
             * De la cartera del sistema anterior: de la apertura o
             * identificado al apartar otra cuota y después liberado. Y
             * entero: el papel se entrega o se deposita completo.
             */
            if ($cheque === null
                || ! $cheque->origin->isLegacy()
                || ! Decimal::equals($cheque->amount, $amount)
                || $cheque->medium !== PaymentMedium::Cheque
                || $cheque->cheque_status !== ChequeStatus::InCustody
                || $cheque->reversal_event_id !== null
                || $cheque->cash_box_id !== $cashBoxId
                || $cheque->currency !== $currency->value
            ) {
                throw ValidationException::withMessages([
                    'source' => 'El cheque tiene que ser uno del sistema anterior, todavía en custodia, y se aparta entero.',
                ]);
            }

            return;
        }

        if ($medium === PaymentMedium::Bank) {
            $this->assertBankAccount($cashBoxId, $currency, $amount, $bankAccountId);

            return;
        }

        $disponible = $this->saldos->of(LedgerAccount::CashOnHand, $cashBoxId, $currency);

        if (Decimal::isNegative(Decimal::sub($disponible, $amount))) {
            throw ValidationException::withMessages([
                'amount' => sprintf(
                    'En «%s» hay %s y esto es por %s.',
                    LedgerAccount::CashOnHand->label(),
                    Decimal::format($disponible),
                    Decimal::format($amount),
                ),
            ]);
        }
    }

    /**
     * Un cheque nuevo sale de lo que la apertura declaró sin detallar.
     *
     * Más que eso sería un cheque que la caja no tiene: la apertura dijo
     * cuánto había en cheques, y lo ya identificado se descuenta. El mismo
     * número de un cheque que sigue en la cartera es el mismo papel.
     *
     * @param  numeric-string  $amount
     * @param  array{number: string, bank: string|null, issueDate: string|null}  $newCheque
     *
     * @throws ValidationException
     */
    private function assertIdentifiable(int $cashBoxId, Currency $currency, string $amount, array $newCheque): void
    {
        $numero = trim($newCheque['number']);

        if ($numero === '') {
            throw ValidationException::withMessages([
                'source' => 'Falta el número del cheque.',
            ]);
        }

        $repetido = FundReceipt::query()
            ->where('medium', PaymentMedium::Cheque->value)
            ->where('cheque_status', ChequeStatus::InCustody->value)
            ->whereNull('reversal_event_id')
            ->whereRaw('upper(btrim(cheque_number)) = upper(?)', [$numero])
            ->when(
                $newCheque['bank'] !== null && trim($newCheque['bank']) !== '',
                fn ($query) => $query->whereRaw('upper(btrim(cheque_bank)) = upper(btrim(?))', [$newCheque['bank']]),
            )
            ->exists();

        if ($repetido) {
            throw ValidationException::withMessages([
                'source' => "El cheque {$numero} ya está en la cartera: elegilo de la lista.",
            ]);
        }

        $sinDetallar = $this->sinDetallar->amount($cashBoxId, $currency);

        if (Decimal::isNegative(Decimal::sub($sinDetallar, $amount))) {
            throw ValidationException::withMessages([
                'source' => sprintf(
                    'De los cheques de la apertura quedan %s sin identificar y este es por %s.',
                    Decimal::format($sinDetallar),
                    Decimal::format($amount),
                ),
            ]);
        }
    }

    /**
     * El depósito directo está en **esa** cuenta.
     *
     * El saldo bancario total no alcanza: con plata en la cuenta A y nada
     * en la B, apartar diciendo B dejaría registrada una ubicación que no
     * existe. Se mira el saldo de la cuenta elegida, que tiene que estar
     * activa y ser de la moneda del haber.
     *
     * Se lee con `DB::table` y no con el modelo de Banking, por el mismo
     * motivo que en `journal_lines`: `bank_accounts` es el maestro de las
     * cuentas del organismo, no dominio de Banking.
     *
     * @param  numeric-string  $amount
     *
     * @throws ValidationException
     */
    private function assertBankAccount(int $cashBoxId, Currency $currency, string $amount, ?int $bankAccountId): void
    {
        if ($bankAccountId === null) {
            throw ValidationException::withMessages([
                'bankAccountId' => 'Un depósito directo tiene que decir en qué cuenta está.',
            ]);
        }

        $cuenta = DB::table('bank_accounts')
            ->where('id', $bankAccountId)
            ->where('is_active', true)
            ->where('currency', $currency->value)
            ->first(['id', 'label']);

        if ($cuenta === null) {
            throw ValidationException::withMessages([
                'bankAccountId' => 'Esa cuenta no está activa o no es de la moneda del haber.',
            ]);
        }

        $disponible = $this->saldos->ofBankAccount($cashBoxId, $currency, $bankAccountId);

        if (Decimal::isNegative(Decimal::sub($disponible, $amount))) {
            throw ValidationException::withMessages([
                'bankAccountId' => sprintf(
                    'En la cuenta «%s» hay %s y esto es por %s.',
                    $cuenta->label,
                    Decimal::format($disponible),
                    Decimal::format($amount),
                ),
            ]);
        }
    }
}
