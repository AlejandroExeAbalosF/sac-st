<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Modules\Banking\Enums\BankAllocationRole;
use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\BankTransactionAllocation;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Support\AllocatableAmount;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El banco acreditó lo que estaba en tránsito. Cierra la ventana.
 *
 * Es la segunda mitad del traslado. Hasta acá el efectivo salió de la caja
 * y quedó en `CASH_IN_TRANSIT`, que es un saldo que envejece a la vista;
 * esto lo lleva a `BANK_ACCOUNT` contra el crédito del extracto que lo
 * confirma.
 *
 * **Y de paso impide contar la plata dos veces.** El crédito de nuestro
 * propio depósito llega al extracto como cualquier otro y, sin esto,
 * alguien podría registrarlo además como una recepción de fondos —el
 * mismo dinero entrando dos veces, una desde la caja y otra desde la
 * nada—. Al imputarlo acá, el movimiento se queda sin saldo libre y deja
 * de ofrecerse.
 */
final class ConfirmCashDepositCredit
{
    public function __construct(
        private readonly PostJournalEntry $asentar,
        private readonly AllocatableAmount $disponible,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /** @throws ValidationException */
    public function handle(
        CashToBankTransfer $transfer,
        BankTransaction $transaction,
        string $idempotencyKey,
        ?int $actorId = null,
    ): CashToBankTransfer {
        return DB::transaction(function () use ($transfer, $transaction, $idempotencyKey, $actorId): CashToBankTransfer {
            $transfer->refresh();

            /*
             * Un segundo envío del mismo formulario. Se resuelve antes que
             * cualquier validación: para entonces el traslado ya está
             * confirmado y validarlo daría un error donde no lo hay.
             */
            if ($transfer->status === CashTransferStatus::BankConfirmed) {
                return $transfer;
            }

            $this->assertConfirmable($transfer, $transaction);

            $importe = Decimal::scale($transfer->amount);

            $evento = $this->asentar->handle(
                type: FinancialEventType::CashDepositCredited,
                idempotencyKey: $idempotencyKey,
                lines: [
                    EntryLine::debit(LedgerAccount::BankAccount, $importe)
                        ->onBankAccount($transfer->bank_account_id),
                    EntryLine::credit(LedgerAccount::CashInTransit, $importe)
                        ->onBankAccount($transfer->bank_account_id)
                        ->onCashBox($transfer->cash_box_id),
                ],
                date: $transaction->transaction_date ?? $transfer->deposit_date,
                cashBoxId: $transfer->cash_box_id,
                description: 'Acreditación del depósito de efectivo',
                actorId: $actorId,
            );

            BankTransactionAllocation::query()->create([
                'bank_transaction_id' => $transaction->id,
                'financial_event_id' => $evento->id,
                'allocation_role' => BankAllocationRole::CashDepositConfirmation,
                'amount' => $importe,
                'allocated_by' => $actorId,
                'allocated_at' => now(),
            ]);

            $transfer->forceFill([
                'credit_event_id' => $evento->id,
                'status' => CashTransferStatus::BankConfirmed,
            ])->save();

            $this->updateReconciliation($transaction);

            $this->auditar->handle('traslado.acreditado', $transfer, after: [
                'bank_transaction_id' => $transaction->id,
                'amount' => $importe,
            ], actorId: $actorId);

            return $transfer;
        });
    }

    /** @throws ValidationException */
    private function assertConfirmable(CashToBankTransfer $transfer, BankTransaction $transaction): void
    {
        if ($transfer->status !== CashTransferStatus::Deposited) {
            throw ValidationException::withMessages([
                'transferId' => 'Ese traslado no está esperando acreditación.',
            ]);
        }

        if ($transaction->bank_account_id !== $transfer->bank_account_id) {
            throw ValidationException::withMessages([
                'bankTransactionId' => 'Ese movimiento es de otra cuenta bancaria.',
            ]);
        }

        if ($transaction->direction !== TransactionDirection::Credit) {
            throw ValidationException::withMessages([
                'bankTransactionId' => 'Un depósito acredita: ese movimiento es un débito.',
            ]);
        }

        if ($transaction->reconciliation_status === ReconciliationStatus::Ignored) {
            throw ValidationException::withMessages([
                'bankTransactionId' => 'Ese movimiento está marcado como ignorado.',
            ]);
        }

        /*
         * El crédito de un depósito entra completo —las comisiones son
         * movimientos aparte—, así que se exige exacto en vez de admitir
         * una imputación parcial que dejaría el traslado a medio cerrar.
         */
        if (! Decimal::equals($this->disponible->forUpdate($transaction), $transfer->amount)) {
            throw ValidationException::withMessages([
                'bankTransactionId' => 'Ese movimiento no tiene libre el importe exacto del depósito.',
            ]);
        }

        /*
         * El banco no acredita antes de que uno deposite. Un crédito
         * anterior es de otra cosa, y aceptarlo cerraría una ventana de
         * tránsito que en realidad sigue abierta.
         */
        $fecha = $transaction->transaction_date;

        if ($fecha !== null && $fecha->lt($transfer->deposit_date->copy()->subDay())) {
            throw ValidationException::withMessages([
                'bankTransactionId' => 'Ese crédito es anterior al depósito: no puede ser su acreditación.',
            ]);
        }
    }

    /**
     * El estado del movimiento sale de lo que ya se le imputó — se
     * recalcula en vez de acumularse, igual que en las recepciones.
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
