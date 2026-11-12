<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\FineImportAction;
use App\Modules\Multas\Enums\FineImportIssue;
use App\Modules\Multas\Import\FineImportDecisions;
use App\Modules\Multas\Models\FineImportBatch;
use App\Modules\Multas\Models\FineImportRow;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La misma decisión para todas las filas sin consolidar que tienen un problema.
 *
 * Solo para los problemas en que la planilla se contradice consigo misma
 * (`FineImportIssue::bulk()`): un duplicado, un pago mayor que la multa o una
 * nota se miran fila por fila.
 */
final class ResolveFineImportIssueInBulk
{
    public function __construct(
        private readonly FineImportDecisions $decisiones,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /** @throws ValidationException */
    public function handle(FineImportBatch $lote, FineImportIssue $problema, FineImportAction $accion, ?string $reason, ?int $actorId): int
    {
        if (! $problema->bulk() || ! in_array($accion, $problema->actions(), true)) {
            throw ValidationException::withMessages(['action' => 'Ese problema se resuelve fila por fila.']);
        }

        return DB::transaction(function () use ($lote, $problema, $accion, $reason, $actorId): int {
            $lote = $this->decisiones->reviewableBatch($lote->id);
            $cantidad = 0;

            $filas = FineImportRow::query()
                ->where('fine_import_batch_id', $lote->id)
                ->whereNull('fine_id')
                ->whereJsonContains('issues', [['code' => $problema->value]])
                ->whereDoesntHave('resolutions', fn ($q) => $q->where('issue_code', $problema->value))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($filas as $fila) {
                $this->decisiones->record($fila, $problema, $accion, $reason, $actorId, null);
                $cantidad++;
            }

            $this->auditar->handle('multa.importacion-resolucion', $lote, metadata: [
                'fila' => "{$cantidad} filas",
                'problema' => $problema->label(),
                'accion' => $accion->label(),
                'reason' => $reason,
            ], actorId: $actorId);

            return $cantidad;
        });
    }
}
