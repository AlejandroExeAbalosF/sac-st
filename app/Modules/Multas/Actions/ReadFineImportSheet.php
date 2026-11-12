<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\FineImportStatus;
use App\Modules\Multas\Import\FineImportFile;
use App\Modules\Multas\Import\HistoricalWorkbook;
use App\Modules\Multas\Import\RowClassifier;
use App\Modules\Multas\Models\FineImportBatch;
use App\Modules\Multas\Models\FineImportRow;
use Illuminate\Support\Facades\DB;

/**
 * Lee una hoja de la planilla y guarda cada fila con lo que decía y lo que se
 * le encontró. No crea multas: eso es consolidar.
 *
 * Una hoja entera o nada, en una transacción: si el trabajo se repite, la hoja
 * ya está y no se lee de nuevo.
 */
final class ReadFineImportSheet
{
    private const TANDA = 500;

    public function __construct(
        private readonly HistoricalWorkbook $libro,
        private readonly RowClassifier $clasificador,
        private readonly FineImportFile $archivo,
    ) {}

    public function handle(FineImportBatch $lote, string $hoja): void
    {
        if ($lote->status !== FineImportStatus::Reading
            || FineImportRow::query()->where('fine_import_batch_id', $lote->id)->where('sheet', $hoja)->exists()) {
            return;
        }

        ['layout' => $layout, 'rows' => $filas] = $this->archivo->using($lote, fn (string $ruta): array => $this->libro->read($ruta, $hoja));
        $corte = $lote->cutoff_month->toDateString();
        $nuevas = [];

        foreach ($filas as $fila) {
            $leida = $this->clasificador->classify($layout, $hoja, $fila['row'], $fila['cells'], $corte);

            if ($leida['kind'] === 'empty') {
                continue;
            }

            $nuevas[] = [
                'fine_import_batch_id' => $lote->id,
                'sheet' => $hoja,
                'row_number' => $leida['row'],
                'kind' => $leida['kind'],
                'cells' => json_encode($leida['cells'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'normalized' => json_encode([...$leida['normalized'], 'text_key' => $leida['text_key']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'identity_key' => $leida['identity_key'],
                'issues' => json_encode($leida['issues'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ];
        }

        DB::transaction(function () use ($lote, $hoja, $nuevas): void {
            FineImportBatch::query()->whereKey($lote->id)->lockForUpdate()->first();

            if (FineImportRow::query()->where('fine_import_batch_id', $lote->id)->where('sheet', $hoja)->exists()) {
                return;
            }

            foreach (array_chunk($nuevas, self::TANDA) as $tanda) {
                FineImportRow::query()->insert($tanda);
            }
        });
    }
}
