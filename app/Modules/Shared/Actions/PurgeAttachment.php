<?php

declare(strict_types=1);

namespace App\Modules\Shared\Actions;

use App\Models\User;
use App\Modules\Shared\Jobs\DeleteAttachmentFile;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\AttachmentFileDeletion;
use App\Modules\Shared\Support\Reason;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Elimina del todo un adjunto anulado: la fila y, después del commit, el
 * archivo.
 *
 * En una transacción: relee la fila bloqueada, valida estado y permiso,
 * audita lo que era —el archivo deja de existir—, anota el borrado del
 * archivo y elimina la fila por `purge_attachment()`. El archivo se borra
 * después del commit (`DeleteAttachmentFile`); si falla, la tarea queda
 * pendiente y la retoma el barrido programado.
 */
final class PurgeAttachment
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    public function handle(Attachment $attachment, Reason $reason, User $actor): void
    {
        $tarea = DB::transaction(function () use ($attachment, $reason, $actor): AttachmentFileDeletion {
            $adjunto = Attachment::query()->with('uploader:id,name')->lockForUpdate()->findOrFail($attachment->id);

            if (! $adjunto->canBePurged()) {
                throw ValidationException::withMessages(['reasonCode' => 'Solo se elimina un respaldo ya anulado.']);
            }

            if (! Gate::forUser($actor)->allows('purge', $adjunto)) {
                throw ValidationException::withMessages(['reasonCode' => 'No podés eliminar este archivo.']);
            }

            $this->auditar->handle(
                action: 'adjunto.eliminado',
                subject: $adjunto,
                metadata: [
                    ...$reason->auditMetadata('motivo'),
                    'titulo' => $adjunto->title,
                    'archivo' => $adjunto->original_filename,
                    'sujeto' => $adjunto->subject_type->value.' #'.$adjunto->subject_id,
                    'tipo' => $adjunto->document_type,
                    'mime' => $adjunto->mime_type,
                    'peso' => $adjunto->size_bytes,
                    'sha256' => $adjunto->sha256,
                    'subido_por' => $adjunto->uploader->name ?? 'Sin dato',
                    'anulacion' => Reason::text($adjunto->void_reason_code, $adjunto->void_reason) ?? '',
                ],
                actorId: $actor->id,
            );

            $tarea = AttachmentFileDeletion::query()->create([
                'attachment_id' => $adjunto->id,
                'disk' => $adjunto->storage_disk,
                'object_key' => $adjunto->object_key,
                'requested_by' => $actor->id,
            ]);

            DB::select('SELECT purge_attachment(?)', [$adjunto->id]);

            return $tarea;
        });

        DeleteAttachmentFile::dispatch($tarea->id)->afterCommit();
    }
}
