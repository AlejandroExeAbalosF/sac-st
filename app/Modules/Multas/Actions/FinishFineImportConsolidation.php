<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\FineImportStatus;
use App\Modules\Multas\Models\FineImportBatch;
use App\Modules\Multas\Models\FineImportRow;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Support\Facades\DB;

/** Cierra la consolidación de un lote, con lo que quedó consolidado y lo que falló. */
final class FinishFineImportConsolidation
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    public function handle(FineImportBatch $lote, ?int $actorId): void
    {
        DB::transaction(function () use ($lote, $actorId): void {
            $lote = FineImportBatch::query()->whereKey($lote->id)->lockForUpdate()->firstOrFail();

            if ($lote->status !== FineImportStatus::Consolidating) {
                return;
            }

            $lote->forceFill([
                'status' => FineImportStatus::Consolidated,
                'consolidated_by' => $actorId,
                'consolidated_at' => now(),
            ])->save();

            $this->auditar->handle('multa.importacion-consolidada', $lote, metadata: [
                'consolidadas' => FineImportRow::query()->where('fine_import_batch_id', $lote->id)->whereNotNull('fine_id')->count(),
                'con_error' => FineImportRow::query()->where('fine_import_batch_id', $lote->id)->whereNull('fine_id')->whereNotNull('failure')->count(),
            ], actorId: $actorId);
        });
    }
}
