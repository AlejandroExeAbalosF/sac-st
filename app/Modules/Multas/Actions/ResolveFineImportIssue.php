<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\FineImportAction;
use App\Modules\Multas\Enums\FineImportIssue;
use App\Modules\Multas\Import\FineImportDecisions;
use App\Modules\Multas\Models\FineImportResolution;
use App\Modules\Multas\Models\FineImportRow;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Decide qué hacer con un problema que bloquea una fila, una vez.
 *
 * Cada problema admite sus acciones (`FineImportIssue::actions()`), y la base
 * repite la lista. Vincular, elegir el mismo expediente o agregar meses solo
 * valen con lo que el importador encontró; quedarse con una fila repetida
 * excluye las otras del grupo.
 */
final class ResolveFineImportIssue
{
    public function __construct(
        private readonly FineImportDecisions $decisiones,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /** @throws ValidationException */
    public function handle(
        FineImportRow $fila,
        FineImportIssue $problema,
        FineImportAction $accion,
        ?string $reason,
        ?int $actorId,
        ?int $fineCaseId = null,
    ): FineImportResolution {
        return DB::transaction(function () use ($fila, $problema, $accion, $reason, $actorId, $fineCaseId): FineImportResolution {
            $lote = $this->decisiones->reviewableBatch($fila->fine_import_batch_id);
            $fila = FineImportRow::query()->whereKey($fila->id)->lockForUpdate()->firstOrFail();

            $resolucion = $this->decisiones->record($fila, $problema, $accion, $reason, $actorId, $fineCaseId);

            if ($accion === FineImportAction::Keep) {
                foreach ($this->decisiones->meta($fila, $problema)['rows'] ?? [] as $otra) {
                    $repetida = FineImportRow::query()->whereKey((int) $otra['id'])->lockForUpdate()->first();

                    if ($repetida instanceof FineImportRow && $repetida->fine_id === null
                        && ! $repetida->resolutions()->where('issue_code', FineImportIssue::DuplicateInFile)->exists()) {
                        $this->decisiones->record($repetida, FineImportIssue::DuplicateInFile, FineImportAction::Exclude,
                            "Se importa {$fila->sheet}, fila {$fila->row_number}.", $actorId, null);
                    }
                }
            }

            $this->auditar->handle('multa.importacion-resolucion', $lote, metadata: [
                'fila' => "{$fila->sheet}, fila {$fila->row_number}",
                'problema' => $problema->label(),
                'accion' => $accion->label(),
                'reason' => $reason,
            ], actorId: $actorId);

            return $resolucion;
        });
    }
}
