<?php

declare(strict_types=1);

namespace App\Modules\Multas\Import;

use App\Modules\Multas\Enums\FineImportAction;
use App\Modules\Multas\Models\FineImportBatch;
use App\Modules\Multas\Models\FineImportRow;
use Illuminate\Database\Eloquent\Builder;

/**
 * Las filas de un lote que se pueden consolidar: multas sin consolidar, sin
 * exclusiones y con cada problema que bloquea resuelto. La base lo repite al
 * marcarlas (`fine_import_rows_guard`).
 */
final class FineImportReadyRows
{
    /**
     * @return Builder<FineImportRow>
     */
    public function of(FineImportBatch $lote): Builder
    {
        return FineImportRow::query()
            ->where('fine_import_batch_id', $lote->id)
            ->where('kind', 'fine')
            ->whereNull('fine_id')
            ->whereDoesntHave('resolutions', fn ($q) => $q->where('action', FineImportAction::Exclude))
            ->whereRaw(<<<'SQL'
                NOT EXISTS (
                    SELECT 1 FROM jsonb_array_elements(fine_import_rows.issues) i
                     WHERE (i->>'blocking')::boolean
                       AND NOT EXISTS (
                           SELECT 1 FROM fine_import_resolutions r
                            WHERE r.fine_import_row_id = fine_import_rows.id AND r.issue_code = i->>'code'
                       )
                )
            SQL)
            // Ya importada y sin cambios: no hay nada que consolidar.
            ->where(fn ($q) => $q->whereNull('previous_row_id')
                ->orWhereHas('resolutions', fn ($r) => $r->where('action', FineImportAction::AddMonths)));
    }
}
