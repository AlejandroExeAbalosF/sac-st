<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Banking\Actions\CancelCashToBankTransfer;
use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Multas\Support\FineDeposits;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deshace el depósito de un cobro que no ocurrió: la plata vuelve a la caja.
 *
 * Solo lo que sigue esperando acreditación. El cobro vuelve a figurar para
 * depositar y el traslado cancelado queda en el historial (M06). Lo hace
 * Banking (`CancelCashToBankTransfer`); acá queda el rastro en la multa.
 */
final class CancelFineDeposit
{
    public function __construct(
        private readonly CancelCashToBankTransfer $cancelar,
        private readonly FineDeposits $depositos,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /** @throws ValidationException */
    public function handle(
        CashToBankTransfer $traslado,
        string $reason,
        string $idempotencyKey,
        ?int $actorId,
    ): CashToBankTransfer {
        return DB::transaction(function () use ($traslado, $reason, $idempotencyKey, $actorId): CashToBankTransfer {
            $yaCancelado = CashToBankTransfer::query()->lockForUpdate()->findOrFail($traslado->id)->status
                === CashTransferStatus::Cancelled;

            $traslado = $this->cancelar->handle($traslado, $reason, $idempotencyKey, $actorId);

            // Un segundo envío: el rastro ya está.
            if (! $yaCancelado) {
                $pago = $this->depositos->paymentOf($traslado);

                if ($pago !== null) {
                    $this->auditar->handle('multa.deposito-cancelado', $pago->fine, metadata: [
                        'amount' => $traslado->amount,
                        'traslado' => 'N.º '.$traslado->id,
                        'motivo' => trim($reason),
                    ], actorId: $actorId);
                }
            }

            return $traslado;
        });
    }
}
