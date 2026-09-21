<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Modules\Ledger\Enums\ChequeStatus;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Support\Money\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El dinero que entra por mostrador.
 *
 * ```text
 *   Débito   CASH_ON_HAND       está en la caja física
 *   Crédito  UNASSIGNED_FUNDS   y todavía no se sabe de quién es
 * ```
 *
 * **La contracara de la recepción bancaria, y su diferencia importa.** Un
 * crédito bancario ya ocurrió cuando el sistema lo ve: viene del extracto,
 * y el papel que lo respalda —el ticket del expediente— se busca contra
 * él. Acá al revés: el hecho ocurre en el mostrador, delante del operador,
 * y no hay nada externo contra qué contrastarlo. Por eso esta recepción no
 * parte de ningún documento previo, y por eso el recibo de ingreso se
 * emite **al recibir**, sin esperar acreditación de nada (§2.5.4).
 *
 * El cheque entra por acá y no por el circuito bancario (§2.5): llega por
 * mostrador, queda en custodia, y se entrega o se deposita. Esperar un
 * crédito en el extracto sería esperar algo que no va a pasar hasta que
 * alguien lo lleve al banco.
 */
final class RegisterCashFundReceipt
{
    public function __construct(
        private readonly PostJournalEntry $asentar,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @param  array{number: string, bank: string|null, issueDate: string|null}|null  $cheque
     *
     * @throws ValidationException
     */
    public function handle(
        string $amount,
        string $idempotencyKey,
        int $cashBoxId,
        CarbonInterface $receivedDate,
        PaymentMedium $medium = PaymentMedium::Cash,
        ?int $depositorId = null,
        ?int $actorId = null,
        ?string $notes = null,
        ?array $cheque = null,
    ): FundReceipt {
        $importe = Decimal::scale($amount);

        $this->assertReceivable($importe, $medium, $cheque);

        return DB::transaction(function () use (
            $importe, $idempotencyKey, $cashBoxId, $receivedDate, $medium, $depositorId, $actorId, $notes, $cheque
        ): FundReceipt {
            $yaRegistrada = FundReceipt::query()
                ->whereRelation('financialEvent', 'idempotency_key', $idempotencyKey)
                ->first();

            if ($yaRegistrada !== null) {
                return $yaRegistrada;
            }

            /*
             * El cheque va a `CHEQUES_IN_CUSTODY` y el efectivo a
             * `CASH_ON_HAND`. Separarlos desde el ingreso es lo que
             * permite que el reverso de la planilla de caja —que lista
             * los cheques uno por uno— salga de una consulta y no de un
             * recuento aparte.
             */
            $cuenta = $medium === PaymentMedium::Cheque
                ? LedgerAccount::ChequesInCustody
                : LedgerAccount::CashOnHand;

            $evento = $this->asentar->handle(
                type: FinancialEventType::FundsReceived,
                idempotencyKey: $idempotencyKey,
                lines: [
                    EntryLine::debit($cuenta, $importe)->onCashBox($cashBoxId),
                    EntryLine::credit(LedgerAccount::UnassignedFunds, $importe)
                        ->from($depositorId)
                        ->onCashBox($cashBoxId),
                ],
                date: $receivedDate,
                cashBoxId: $cashBoxId,
                description: $notes,
                actorId: $actorId,
            );

            $recepcion = FundReceipt::query()->create([
                'financial_event_id' => $evento->id,
                'cash_box_id' => $cashBoxId,
                'depositor_id' => $depositorId,
                'medium' => $medium,
                'amount' => $importe,
                'received_date' => $receivedDate,
                'received_by' => $actorId,
                'notes' => $notes,
                'cheque_number' => $cheque['number'] ?? null,
                'cheque_bank' => $cheque['bank'] ?? null,
                'cheque_issue_date' => $cheque['issueDate'] ?? null,
                // Entra en custodia: el papel está en poder del organismo
                // hasta que se entregue o se deposite.
                'cheque_status' => $medium === PaymentMedium::Cheque
                    ? ChequeStatus::InCustody
                    : null,
            ]);

            $this->auditar->handle('recepcion.registrada', $recepcion, after: [
                'medium' => $medium->value,
                'amount' => $importe,
                'financial_event_id' => $evento->id,
            ], actorId: $actorId);

            return $recepcion;
        });
    }

    /**
     * @param  array{number: string, bank: string|null, issueDate: string|null}|null  $cheque
     *
     * @throws ValidationException
     */
    private function assertReceivable(string $amount, PaymentMedium $medium, ?array $cheque): void
    {
        if (Decimal::isNegative($amount) || Decimal::equals($amount, '0')) {
            throw ValidationException::withMessages([
                'amount' => 'El importe recibido tiene que ser mayor que cero.',
            ]);
        }

        /*
         * Este Action es el del mostrador. Una transferencia no se
         * registra acá: nace de un crédito del extracto, y dejarla entrar
         * por este camino crearía dinero bancario que ningún movimiento
         * respalda.
         */
        if ($medium === PaymentMedium::Bank) {
            throw ValidationException::withMessages([
                'medium' => 'Una recepción bancaria se registra desde el movimiento del extracto que la trajo.',
            ]);
        }

        if ($medium === PaymentMedium::Cheque && ($cheque['number'] ?? null) === null) {
            throw ValidationException::withMessages([
                'cheque' => 'Un cheque sin número no se puede inventariar ni buscar después.',
            ]);
        }
    }
}
