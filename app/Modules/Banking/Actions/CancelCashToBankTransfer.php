<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Support\CashTransferContents;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Support\InverseEntry;
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
 * El asiento inverso devuelve el dinero de `CASH_IN_TRANSIT` a la cuenta de
 * la que salió —el efectivo a la caja, el cheque a custodia—, los cheques
 * que viajaron vuelven a figurar en custodia, y el traslado queda
 * `cancelled` con su motivo.
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
        private readonly InverseEntry $inverso,
        private readonly CashTransferContents $contenido,
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
             * El inverso del asiento del depósito, línea por línea. El
             * efectivo vuelve a la caja y el cheque a custodia —cada uno a
             * la cuenta de la que salió—, en la moneda en que salió. Lo que
             * se deshace es la afirmación de que salieron.
             */
            $evento = $this->asentar->handle(
                type: FinancialEventType::Reversal,
                idempotencyKey: $idempotencyKey,
                lines: $this->inverso->of($bloqueado->deposit_event_id),
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

            // Los cheques que viajaron vuelven a custodia.
            $this->contenido->cancelled($bloqueado);

            $this->auditar->handle('traslado.cancelado', $bloqueado, null, null, [
                'amount' => $importe,
                'reversal_event_id' => $evento->id,
                'motivo' => $reason,
            ], actorId: $actorId);

            return $bloqueado->refresh();
        });
    }
}
