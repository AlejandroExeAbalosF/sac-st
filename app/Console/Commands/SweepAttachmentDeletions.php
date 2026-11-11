<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Shared\Models\AttachmentFileDeletion;
use App\Modules\Shared\Support\AttachmentFileRemover;
use Illuminate\Console\Command;

/**
 * Retoma los borrados de archivos que quedaron pendientes.
 *
 * Un adjunto eliminado anota el borrado de su archivo en la misma
 * transacción; el trabajo en cola lo hace después del commit. Si el proceso
 * se cortó en el medio, o el disco falló todas las veces, la tarea sigue
 * pendiente: este barrido la vuelve a intentar. Corre programado.
 */
final class SweepAttachmentDeletions extends Command
{
    protected $signature = 'attachments:sweep-deletions';

    protected $description = 'Borra del disco los archivos de adjuntos eliminados que quedaron pendientes';

    public function handle(AttachmentFileRemover $remover): int
    {
        $borrados = 0;
        $pendientes = 0;

        /*
         * Por id y no por desplazamiento: el conjunto de pendientes se achica
         * a medida que se cumplen, y paginar con OFFSET saltaba tareas del
         * lote siguiente; terminaba con éxito dejando algunas sin intentar.
         */
        AttachmentFileDeletion::query()->pending()->eachById(function (AttachmentFileDeletion $tarea) use ($remover, &$borrados, &$pendientes): void {
            $remover->process($tarea) ? $borrados++ : $pendientes++;
        });

        $this->info("Archivos borrados: {$borrados}. Siguen pendientes: {$pendientes}.");

        return $pendientes === 0 ? self::SUCCESS : self::FAILURE;
    }
}
