<?php

declare(strict_types=1);

namespace App\Modules\Shared\Jobs;

use App\Modules\Shared\Models\AttachmentFileDeletion;
use App\Modules\Shared\Support\AttachmentFileRemover;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Borra el archivo de un adjunto eliminado, después del commit.
 *
 * Reintenta con esperas crecientes. Si se agotan los intentos, la tarea
 * queda pendiente en `attachment_file_deletions` y la retoma el barrido
 * programado (`attachments:sweep-deletions`): nada depende de que este
 * trabajo llegue a correr.
 */
final class DeleteAttachmentFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $deletionId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(AttachmentFileRemover $remover): void
    {
        $tarea = AttachmentFileDeletion::query()->find($this->deletionId);

        if ($tarea === null || $remover->process($tarea)) {
            return;
        }

        throw new RuntimeException("El archivo de la tarea {$this->deletionId} sigue en el disco.");
    }
}
