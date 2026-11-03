<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\FineAppealStatus;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineAppeal;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Support\Reason;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Descarta un pago informado que no corresponde: es de otra multa, está
 * duplicado, el banco nunca lo acreditó.
 *
 * Nunca tocó la deuda ni tuvo recibo, así que descartarlo no mueve nada:
 * queda a la vista con quién lo descartó y por qué. También lo usa la
 * corrección de un informado, antes de registrar el corregido.
 *
 * Descartar el depósito de un recurso en trámite lo hace **caer**: la multa
 * se descongela y admite otro. En una corrección el trámite sigue, con el
 * pago nuevo. Si el recurso ya tiene veredicto, el veredicto se conserva.
 */
final class DiscardFinePayment
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    /**
     * @throws ValidationException
     */
    public function handle(FinePayment $pago, Reason $motivo, ?int $actorId, bool $keepAppeal = false): FinePayment
    {
        return DB::transaction(function () use ($pago, $motivo, $actorId, $keepAppeal): FinePayment {
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
                'discard_reason_code' => $motivo->code,
                'discard_reason' => $motivo->detail,
            ])->save();

            $this->auditar->handle('multa.pago-descartado', $multa, metadata: [
                'medio' => $pago->medium->label(),
                'amount' => $pago->amount,
                ...$motivo->auditMetadata(),
            ], actorId: $actorId);

            $recurso = $pago->fine_appeal_id === null || $keepAppeal
                ? null
                : FineAppeal::query()->whereKey($pago->fine_appeal_id)->lockForUpdate()->first();

            if ($recurso?->status === FineAppealStatus::Open) {
                $recurso->forceFill(['status' => FineAppealStatus::Dropped])->save();

                $this->auditar->handle('multa.recurso-caido', $multa, metadata: [
                    'amount' => $pago->amount,
                    ...$motivo->auditMetadata(),
                ], actorId: $actorId);
            }

            return $pago;
        });
    }
}
