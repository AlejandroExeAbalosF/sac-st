<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Modules\Banking\Enums\BankAllocationRole;
use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\BankTransactionAllocation;
use App\Modules\Banking\Support\AllocatableAmount;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Convierte un crédito bancario en dinero asentado.
 *
 * Es el paso que faltaba. Hasta acá el sistema sabía que el banco informó
 * un crédito y que un ticket lo respalda; a partir de acá ese dinero
 * existe en los libros:
 *
 * ```text
 *   Débito   BANK_ACCOUNT       está en la cuenta
 *   Crédito  UNASSIGNED_FUNDS   y todavía no se sabe de quién es
 * ```
 *
 * **Todavía no financia ninguna cuota.** Eso es la asignación, y es
 * deliberado que sean dos actos: reconocer que entró plata y decidir de
 * quién es son decisiones distintas, y el saldo de `UNASSIGNED_FUNDS`
 * —todo lo que entró y nadie identificó— es precisamente la cola de
 * trabajo que el área hoy lleva a mano.
 *
 * **No sabe que existen tickets ni expedientes**, y no le hace falta: el
 * ticket ya apunta al movimiento, así que la cadena
 * `ticket → movimiento → imputación → evento → recepción` se recorre sin
 * que Banking tenga que mirar hacia Haberes.
 */
final class RegisterBankFundReceipt
{
    public function __construct(
        private readonly PostJournalEntry $asentar,
        private readonly AllocatableAmount $disponible,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @param  string  $idempotencyKey  Derivada del formulario, no del clic:
     *                                  es lo que distingue un segundo envío
     *                                  accidental de una segunda recepción real
     *                                  sobre el mismo movimiento.
     *
     * @throws ValidationException
     */
    public function handle(
        BankTransaction $transaction,
        string $amount,
        string $idempotencyKey,
        int $cashBoxId,
        ?int $depositorId = null,
        ?int $actorId = null,
        ?string $notes = null,
    ): FundReceipt {
        $importe = Decimal::scale($amount);

        $this->assertReceivable($transaction, $importe);

        return DB::transaction(function () use (
            $transaction, $importe, $idempotencyKey, $cashBoxId, $depositorId, $actorId, $notes
        ): FundReceipt {
            /*
             * La idempotencia se resuelve **antes** que el disponible, y
             * el orden importa.
             *
             * En un segundo envío del mismo formulario el disponible ya
             * está consumido por el primero, así que validarlo antes
             * respondería «no queda plata» a un operador que lo único que
             * hizo fue apretar dos veces. Primero se pregunta si este
             * hecho ya está asentado; recién si no lo está, si entra.
             */
            $yaAsentado = FundReceipt::query()
                ->whereRelation('financialEvent', 'idempotency_key', $idempotencyKey)
                ->first();

            if ($yaAsentado !== null) {
                return $yaAsentado;
            }

            /*
             * Con el movimiento bloqueado, para que dos operadores no
             * lean el mismo disponible al mismo tiempo. El trigger de la
             * base los frenaría igual, pero acá el segundo recibe una
             * explicación en vez de un error de PostgreSQL.
             */
            $libre = $this->disponible->forUpdate($transaction);

            if (Decimal::isNegative(Decimal::sub($libre, $importe))) {
                throw ValidationException::withMessages([
                    'amount' => sprintf(
                        'Del movimiento quedan $ %s sin imputar y se están pidiendo $ %s.',
                        Decimal::format($libre),
                        Decimal::format($importe),
                    ),
                ]);
            }

            $evento = $this->asentar->handle(
                type: FinancialEventType::FundsReceived,
                idempotencyKey: $idempotencyKey,
                lines: [
                    EntryLine::debit(LedgerAccount::BankAccount, $importe)
                        ->onBankAccount($transaction->bank_account_id)
                        ->onCashBox($cashBoxId),
                    EntryLine::credit(LedgerAccount::UnassignedFunds, $importe)
                        ->from($depositorId)
                        ->onCashBox($cashBoxId),
                ],
                date: $transaction->transaction_date ?? BusinessDate::today(),
                cashBoxId: $cashBoxId,
                description: $notes,
                actorId: $actorId,
            );

            $recepcion = FundReceipt::query()->create([
                'financial_event_id' => $evento->id,
                'cash_box_id' => $cashBoxId,
                'depositor_id' => $depositorId,
                'medium' => PaymentMedium::Bank,
                'amount' => $importe,
                'received_date' => $transaction->transaction_date ?? BusinessDate::today(),
                'received_by' => $actorId,
                'notes' => $notes,
            ]);

            BankTransactionAllocation::query()->create([
                'bank_transaction_id' => $transaction->id,
                'financial_event_id' => $evento->id,
                'allocation_role' => BankAllocationRole::FundsReceived,
                'amount' => $importe,
                'allocated_by' => $actorId,
                'allocated_at' => now(),
            ]);

            $this->updateReconciliation($transaction);

            $this->auditar->handle('recepcion.registrada', $recepcion, after: [
                'bank_transaction_id' => $transaction->id,
                'amount' => $importe,
                'financial_event_id' => $evento->id,
            ], actorId: $actorId);

            return $recepcion;
        });
    }

    /**
     * @throws ValidationException
     */
    private function assertReceivable(BankTransaction $transaction, string $amount): void
    {
        if ($transaction->direction !== TransactionDirection::Credit) {
            throw ValidationException::withMessages([
                'bank_transaction_id' => 'Una recepción de fondos necesita un crédito: ese movimiento es un débito.',
            ]);
        }

        if ($transaction->reconciliation_status === ReconciliationStatus::Ignored) {
            throw ValidationException::withMessages([
                'bank_transaction_id' => 'El movimiento está marcado como ignorado. Hay que reactivarlo antes de imputarlo.',
            ]);
        }

        if (Decimal::isNegative($amount) || Decimal::equals($amount, '0')) {
            throw ValidationException::withMessages([
                'amount' => 'El importe de la recepción tiene que ser mayor que cero.',
            ]);
        }
    }

    /**
     * El estado del movimiento sale de lo que ya se le imputó.
     *
     * Es un marcador administrativo —de los que el §8 sí deja mutables— y
     * se recalcula en vez de acumularse, por la misma razón de siempre: un
     * contador que se incrementa se desincroniza; una suma, no.
     */
    private function updateReconciliation(BankTransaction $transaction): void
    {
        $imputado = $this->disponible->allocated($transaction);
        $total = Decimal::abs($transaction->amount);

        $estado = match (true) {
            Decimal::equals($imputado, '0') => ReconciliationStatus::Pending,
            Decimal::equals($imputado, $total) => ReconciliationStatus::Reconciled,
            default => ReconciliationStatus::Partial,
        };

        $transaction->forceFill(['reconciliation_status' => $estado])->save();
    }
}
