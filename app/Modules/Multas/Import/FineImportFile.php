<?php

declare(strict_types=1);

namespace App\Modules\Multas\Import;

use App\Modules\Multas\Models\FineImportBatch;
use App\Modules\Shared\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * El archivo de un lote, en el disco local, para leerlo.
 *
 * El original vive como adjunto del lote. Cada trabajo de la cola saca su
 * propia copia y la borra al terminar: un worker no comparte disco con otro.
 */
final class FineImportFile
{
    /**
     * @template T
     *
     * @param  callable(string): T  $leer
     * @return T
     */
    public function using(FineImportBatch $lote, callable $leer): mixed
    {
        $adjunto = Attachment::query()->findOrFail($lote->attachment_id);
        $contenido = Storage::disk($adjunto->storage_disk)->get($adjunto->object_key);

        if ($contenido === null) {
            throw new RuntimeException("No se encontró el archivo del lote {$lote->id}.");
        }

        $ruta = tempnam(sys_get_temp_dir(), 'multas-planilla-');

        if ($ruta === false || file_put_contents($ruta, $contenido) === false) {
            throw new RuntimeException('No se pudo copiar la planilla para leerla.');
        }

        try {
            return $leer($ruta);
        } finally {
            @unlink($ruta);
        }
    }
}
