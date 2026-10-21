<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Descarta un pago informado que no corresponde: es de otra multa, está
 * duplicado, el banco nunca lo acreditó.
 *
 * Nunca tocó la deuda ni tuvo recibo, así que descartarlo no mueve nada:
 * queda a la vista con quién lo descartó y por qué. También lo usa la
 * corrección de un informado, antes de registrar el corregido.
 */
final class DiscardFinePayment
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    /**
     * @throws ValidationException
     */
    public function handle(FinePayment $pago, string $reason, ?int $actorId): FinePayment
    {
        $motivo = trim($reason);

        if (mb_strlen($motivo) < 5) {
            throw ValidationException::withMessages(['reason' => 'Decí por qué se descarta el pago.']);
        }

        return DB::transaction(function () use ($pago, $motivo, $actorId): FinePayment {
            $multa = Fine::query()->whereKey($pago->fine_id)->lockForUpdate()->firstOrFail();
            $pago = FinePayment::query()->whereKey($pago->id)->lockForUpdate()->firstOrFail();

            if ($pago->status !== FinePaymentStatus::Reported) {
                throw ValidationException::withMessages([
                    'reason' => 'Ese pago ya no está informado: lo que ya se aplicó no se descarta ni se corrige.',
                ]);
            }

            $pago->forceFill([
                'status' => FinePaymentStatus::Discarded,
                'discarded_by' => $actorId,
                'discarded_at' => now(),
                'discard_reason' => $motivo,
            ])->save();

            $this->auditar->handle('multa.pago-descartado', $multa, metadata: [
                'medio' => $pago->medium->label(),
                'amount' => $pago->amount,
                'reason' => $motivo,
            ], actorId: $actorId);

            return $pago;
        });
    }
}
