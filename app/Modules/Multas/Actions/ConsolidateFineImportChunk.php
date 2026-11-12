<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Import\FineImportReadyRows;
use App\Modules\Multas\Models\FineImportBatch;
use App\Modules\Multas\Models\FineImportRow;
use Throwable;

/**
 * Una tanda de la consolidación, desde la fila siguiente a la última que se
 * miró. Cada fila en su transacción: una que falla no frena las demás y queda
 * con su error.
 */
final class ConsolidateFineImportChunk
{
    public const TANDA = 300;

    public function __construct(
        private readonly FineImportReadyRows $listas,
        private readonly ConsolidateFineImportRow $consolidar,
    ) {}

    /** Devuelve la última fila que miró, o `null` si ya no quedan. */
    public function handle(FineImportBatch $lote, int $despuesDe, ?int $actorId): ?int
    {
        $filas = $this->listas->of($lote)->where('id', '>', $despuesDe)->orderBy('id')->limit(self::TANDA)->get();

        foreach ($filas as $fila) {
            try {
                $this->consolidar->handle($fila, $actorId);
            } catch (Throwable $excepcion) {
                FineImportRow::query()->whereKey($fila->id)->whereNull('fine_id')
                    ->update(['failure' => mb_substr($excepcion->getMessage(), 0, 1000)]);
            }
        }

        return $filas->isEmpty() ? null : (int) $filas->last()->id;
    }
}
