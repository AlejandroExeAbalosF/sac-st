<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Modules\Banking\Enums\BankAllocationRole;
use App\Modules\Banking\Enums\ChequeRejectionBankStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\ChequeRejection;
use App\Modules\Banking\Support\RejectedChequeMovements;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\EntryLine;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El extracto trae el débito con que el banco revierte un cheque rechazado.
 *
 * Cierra el débito anunciado: D `PENDING_BANK_DEBITS` / C `BANK_ACCOUNT`, con
 * el movimiento entero imputado. Recién acá baja la cuenta del banco, porque
 * recién acá el extracto lo dice. No toca la regularización de la empresa:
 * el reemplazo puede haber llegado antes o llegar después.
 */
final class RecordChequeRejectionDebit
{
    public function __construct(
        private readonly PostJournalEntry $asentar,
        private readonly RejectedChequeMovements $movimientos,
    ) {}

    /** @throws ValidationException */
    public function handle(ChequeRejection $rechazo, BankTransaction $debito, string $idempotencyKey, ?int $actorId): ChequeRejection
    {
        return DB::transaction(function () use ($rechazo, $debito, $idempotencyKey, $actorId): ChequeRejection {
            $rechazo = ChequeRejection::query()->with('transfer')->lockForUpdate()->findOrFail($rechazo->id);

            // Un segundo envío con el mismo débito.
            if ($rechazo->debit_bank_transaction_id === $debito->id) {
                return $rechazo;
            }

            if ($rechazo->bank_status !== ChequeRejectionBankStatus::DebitPending) {
                throw ValidationException::withMessages([
                    'debitTransactionId' => 'Ese rechazo no espera un débito: '.mb_strtolower($rechazo->bank_status->label()).'.',
                ]);
            }

            $this->movimientos->assertUsable($rechazo, $debito, TransactionDirection::Debit);

            $cheque = FundReceipt::query()->findOrFail($rechazo->fund_receipt_id);
            $moneda = Currency::from($cheque->currency);
            $cuenta = (int) $rechazo->transfer?->bank_account_id;

            $evento = $this->asentar->handle(
                type: FinancialEventType::ChequeDebitRecorded,
                idempotencyKey: $idempotencyKey,
                lines: [
                    EntryLine::debit(LedgerAccount::PendingBankDebits, $cheque->amount)
                        ->in($moneda)->onBankAccount($cuenta)->onCashBox($cheque->cash_box_id),
                    EntryLine::credit(LedgerAccount::BankAccount, $cheque->amount)
                        ->in($moneda)->onBankAccount($cuenta)->onCashBox($cheque->cash_box_id),
                ],
                // El día en que se registra; la fecha del banco queda en el movimiento.
                date: BusinessDate::today(),
                cashBoxId: $cheque->cash_box_id,
                description: 'Débito del cheque rechazado n.º '.$cheque->cheque_number,
                actorId: $actorId,
            );

            $this->movimientos->allocate($debito, $evento, BankAllocationRole::ChequeRejectionDebit, $actorId);

            $rechazo->forceFill([
                'bank_status' => ChequeRejectionBankStatus::DebitRecorded,
                'debit_bank_transaction_id' => $debito->id,
                'debit_event_id' => $evento->id,
            ])->save();

            return $rechazo;
        });
    }
}
