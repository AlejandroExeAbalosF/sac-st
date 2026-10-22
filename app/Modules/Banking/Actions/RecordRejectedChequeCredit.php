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
 * El extracto trae el crédito de un cheque que se registró rechazado sin
 * saber si el banco lo había acreditado (`unverified`).
 *
 * El tránsito ya salió al rechazar, así que no se retira dos veces: el
 * crédito entra al banco contra un débito anunciado
 * (D `BANK_ACCOUNT` / C `PENDING_BANK_DEBITS`), que el débito del extracto
 * cierra después —o en el mismo acto, si llegaron juntos—. Ningún asiento
 * anterior se toca.
 */
final class RecordRejectedChequeCredit
{
    public function __construct(
        private readonly PostJournalEntry $asentar,
        private readonly RejectedChequeMovements $movimientos,
        private readonly RecordChequeRejectionDebit $debito,
    ) {}

    /** @throws ValidationException */
    public function handle(
        ChequeRejection $rechazo,
        BankTransaction $credito,
        ?BankTransaction $debito,
        string $idempotencyKey,
        ?int $actorId,
    ): ChequeRejection {
        return DB::transaction(function () use ($rechazo, $credito, $debito, $idempotencyKey, $actorId): ChequeRejection {
            $rechazo = ChequeRejection::query()->with('transfer')->lockForUpdate()->findOrFail($rechazo->id);

            // Un segundo envío con el mismo crédito.
            if ($rechazo->credit_bank_transaction_id === $credito->id) {
                return $rechazo;
            }

            if ($rechazo->bank_status !== ChequeRejectionBankStatus::Unverified) {
                throw ValidationException::withMessages([
                    'creditTransactionId' => 'El crédito se registra solo mientras el rechazo está sin verificar en el extracto.',
                ]);
            }

            $this->movimientos->assertUsable($rechazo, $credito, TransactionDirection::Credit);

            $cheque = FundReceipt::query()->findOrFail($rechazo->fund_receipt_id);
            $moneda = Currency::from($cheque->currency);
            $cuenta = (int) $rechazo->transfer?->bank_account_id;

            $evento = $this->asentar->handle(
                type: FinancialEventType::RejectedChequeCredited,
                idempotencyKey: $idempotencyKey,
                lines: [
                    EntryLine::debit(LedgerAccount::BankAccount, $cheque->amount)
                        ->in($moneda)->onBankAccount($cuenta)->onCashBox($cheque->cash_box_id),
                    EntryLine::credit(LedgerAccount::PendingBankDebits, $cheque->amount)
                        ->in($moneda)->onBankAccount($cuenta)->onCashBox($cheque->cash_box_id),
                ],
                date: BusinessDate::today(),
                cashBoxId: $cheque->cash_box_id,
                description: 'Crédito del cheque rechazado n.º '.$cheque->cheque_number,
                actorId: $actorId,
            );

            $this->movimientos->allocate($credito, $evento, BankAllocationRole::ChequeRejectionCredit, $actorId);

            $rechazo->forceFill([
                'bank_status' => ChequeRejectionBankStatus::DebitPending,
                'credit_bank_transaction_id' => $credito->id,
                'credit_event_id' => $evento->id,
            ])->save();

            // Si el extracto ya trae también el débito, se cierra en el mismo acto.
            return $debito === null
                ? $rechazo
                : $this->debito->handle($rechazo, $debito, $idempotencyKey.':debito', $actorId);
        });
    }
}
