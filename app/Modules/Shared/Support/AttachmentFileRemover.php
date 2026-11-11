<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\AttachmentFileDeletion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Borra del disco el archivo de un adjunto eliminado y lo comprueba.
 *
 * `Storage::delete()` puede devolver falso o lanzar, y que no lance no
 * prueba que el archivo ya no esté: la tarea se da por cumplida solo si el
 * archivo **no existe** después del intento. Cada intento queda anotado.
 *
 * Antes de borrar mira si algún adjunto usa ese archivo. La tarea la anota
 * la base al eliminar la fila, y no la anota si otro la comparte, pero el
 * borrado nunca confía solo en eso: un archivo en uso se conserva y la
 * tarea se cierra diciendo por qué.
 */
final class AttachmentFileRemover
{
    /** Devuelve si el archivo ya no está. */
    public function process(AttachmentFileDeletion $tarea): bool
    {
        return DB::transaction(function () use ($tarea): bool {
            $fila = AttachmentFileDeletion::query()->lockForUpdate()->findOrFail($tarea->id);

            if ($fila->deleted_at !== null || $fila->kept_at !== null) {
                return true;
            }

            $enUso = Attachment::query()
                ->where('storage_disk', $fila->disk)
                ->where('object_key', $fila->object_key)
                ->exists();

            if ($enUso) {
                $fila->forceFill([
                    'attempts' => $fila->attempts + 1,
                    'last_error' => 'Se conservó el archivo: lo usa un adjunto.',
                    'kept_at' => now(),
                ])->save();

                return true;
            }

            $disco = Storage::disk($fila->disk);
            $error = null;

            try {
                $disco->delete($fila->object_key);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }

            $sigue = $disco->exists($fila->object_key);

            $fila->forceFill([
                'attempts' => $fila->attempts + 1,
                'last_error' => $sigue ? mb_substr($error ?? 'El archivo sigue en el disco después de borrarlo.', 0, 1000) : null,
                'deleted_at' => $sigue ? null : now(),
            ])->save();

            return ! $sigue;
        });
    }
}
