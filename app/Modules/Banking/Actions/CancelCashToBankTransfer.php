<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deshace un traslado que no ocurrió: el efectivo vuelve a la caja.
 *
 * Se usa cuando el depósito se cargó por error —la fecha, el importe, o
 * directamente el traslado equivocado— y el banco todavía no lo acreditó.
 * El asiento inverso devuelve el dinero de `CASH_IN_TRANSIT` a la caja, y
 * el traslado queda `cancelled` con su motivo.
 *
 * **Sólo se cancela lo que sigue en tránsito.** Un traslado acreditado ya
 * está confirmado por el extracto: ese dinero está en la cuenta y decir lo
 * contrario sería inventar. La base lo impone además por su cuenta —el
 * `CHECK` ata `credit_event_id` al estado `bank_confirmed`—, así que un
 * acreditado no puede pasar a cancelado ni salteando este Action.
 *
 * Faltaba, y su ausencia dejaba un callejón visible: anular un cobro cuyo
 * efectivo ya se depositó se rechaza —bien— diciendo «hay que revertir
 * primero el traslado», y revertirlo no se podía. Un rechazo que apunta a
 * una puerta que no existe es peor que no rechazar.
 */
final class CancelCashToBankTransfer
{
    public function __construct(
        private readonly PostJournalEntry $asentar,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /** @throws ValidationException */
    public function handle(
        CashToBankTransfer $transfer,
        string $reason,
        string $idempotencyKey,
        ?int $actorId = null,
    ): CashToBankTransfer {
        return DB::transaction(function () use ($transfer, $reason, $idempotencyKey, $actorId): CashToBankTransfer {
            $bloqueado = CashToBankTransfer::query()
                ->lockForUpdate()
                ->findOrFail($transfer->id);

            // Un segundo envío del mismo formulario.
            if ($bloqueado->status === CashTransferStatus::Cancelled) {
                return $bloqueado;
            }

            if ($bloqueado->status === CashTransferStatus::BankConfirmed) {
                throw ValidationException::withMessages([
                    'transferId' => 'El extracto ya confirmó ese depósito: el dinero está en la cuenta. '
                        .'Cancelarlo diría que nunca llegó.',
                ]);
            }

            $importe = Decimal::scale($bloqueado->amount);

            /*
             * El inverso del asiento del depósito. El efectivo vuelve a la
             * caja, que es donde estuvo todo el tiempo: lo que se deshace
             * es la afirmación de que salió.
             */
            $evento = $this->asentar->handle(
                type: FinancialEventType::Reversal,
                idempotencyKey: $idempotencyKey,
                lines: [
                    EntryLine::debit(LedgerAccount::CashOnHand, $importe)
                        ->onCashBox($bloqueado->cash_box_id),
                    EntryLine::credit(LedgerAccount::CashInTransit, $importe)
                        ->onBankAccount($bloqueado->bank_account_id)
                        ->onCashBox($bloqueado->cash_box_id),
                ],
                date: BusinessDate::today(),
                cashBoxId: $bloqueado->cash_box_id,
                description: $reason,
                actorId: $actorId,
                reversalOfId: $bloqueado->deposit_event_id,
                reversalReason: $reason,
            );

            $bloqueado->forceFill([
                'status' => CashTransferStatus::Cancelled,
                'notes' => $reason,
            ])->save();

            $this->auditar->handle('traslado.cancelado', $bloqueado, null, null, [
                'amount' => $importe,
                'reversal_event_id' => $evento->id,
                'motivo' => $reason,
            ], actorId: $actorId);

            return $bloqueado->refresh();
        });
    }
}
