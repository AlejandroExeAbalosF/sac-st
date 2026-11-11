<?php

declare(strict_types=1);

namespace App\Modules\Shared\Actions;

use App\Models\User;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Support\Reason;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Anula un adjunto opcional: queda guardado, fuera de la vista.
 *
 * El estado del archivo y el permiso se validan los dos, sobre la fila
 * releída y bloqueada: si dos personas anulan el mismo adjunto a la vez, la
 * segunda se encuentra con que ya estaba anulado y no se registran dos
 * anulaciones. La base lo impone igual —el trigger rechaza anular dos veces
 * o anular lo que no está en la lista—; esto da el mensaje.
 */
final class VoidAttachment
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    public function handle(Attachment $attachment, Reason $reason, User $actor): Attachment
    {
        return DB::transaction(function () use ($attachment, $reason, $actor): Attachment {
            $adjunto = Attachment::query()->lockForUpdate()->findOrFail($attachment->id);

            if ($adjunto->isVoided()) {
                throw ValidationException::withMessages(['reasonCode' => 'Este archivo ya estaba anulado.']);
            }

            if (! $adjunto->canBeVoided()) {
                throw ValidationException::withMessages(['reasonCode' => 'Este archivo no se anula: es la prueba de un hecho, no un respaldo opcional.']);
            }

            if (! Gate::forUser($actor)->allows('void', $adjunto)) {
                throw ValidationException::withMessages(['reasonCode' => 'No podés anular este archivo.']);
            }

            $adjunto->forceFill([
                'voided_at' => now(),
                'voided_by' => $actor->id,
                'void_reason_code' => $reason->code,
                'void_reason' => $reason->detail,
            ])->save();

            $this->auditar->handle(
                action: 'adjunto.anulado',
                subject: $adjunto,
                metadata: [
                    ...$reason->auditMetadata('motivo'),
                    'sujeto' => $adjunto->subject_type->value.' #'.$adjunto->subject_id,
                ],
                actorId: $actor->id,
            );

            return $adjunto;
        });
    }
}
