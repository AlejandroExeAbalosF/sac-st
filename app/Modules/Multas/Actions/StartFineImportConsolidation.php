<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\FineImportStatus;
use App\Modules\Multas\Import\FineImportReadyRows;
use App\Modules\Multas\Jobs\ConsolidateFineImportJob;
use App\Modules\Multas\Models\FineImportBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Consolidar un lote es un paso explícito del administrador.
 *
 * Toma las filas sin problemas que bloqueen y las que ya tienen todas sus
 * decisiones, y las lleva al sistema de a tandas en la cola. Consolidar otra
 * vez retoma lo que quedó pendiente.
 */
final class StartFineImportConsolidation
{
    public function __construct(private readonly FineImportReadyRows $listas) {}

    /** @throws ValidationException */
    public function handle(FineImportBatch $lote, ?int $actorId): void
    {
        if (! config('multas.historical_import_enabled', false)) {
            throw ValidationException::withMessages(['batch' => 'La importación de la planilla histórica está en pausa.']);
        }

        DB::transaction(function () use ($lote, $actorId): void {
            $lote = FineImportBatch::query()->whereKey($lote->id)->lockForUpdate()->firstOrFail();

            if (! in_array($lote->status, [FineImportStatus::Ready, FineImportStatus::Consolidated], true)) {
                throw ValidationException::withMessages(['batch' => 'El lote todavía se está leyendo o ya se está consolidando.']);
            }

            if (! $this->listas->of($lote)->exists()) {
                throw ValidationException::withMessages(['batch' => 'No hay filas listas para consolidar: resolvé las que bloquean.']);
            }

            $lote->forceFill(['status' => FineImportStatus::Consolidating])->save();

            DB::afterCommit(fn () => ConsolidateFineImportJob::dispatch($lote->id, 0, $actorId));
        });
    }
}
