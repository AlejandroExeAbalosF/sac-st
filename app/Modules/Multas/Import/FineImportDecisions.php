<?php

declare(strict_types=1);

namespace App\Modules\Multas\Import;

use App\Modules\Multas\Enums\FineImportAction;
use App\Modules\Multas\Enums\FineImportIssue;
use App\Modules\Multas\Enums\FineImportStatus;
use App\Modules\Multas\Models\FineImportBatch;
use App\Modules\Multas\Models\FineImportResolution;
use App\Modules\Multas\Models\FineImportRow;
use Illuminate\Validation\ValidationException;

/**
 * Registra una decisión sobre un problema de una fila, con las reglas de cada
 * problema (`FineImportIssue::actions()`), que la base repite. Se usa dentro de
 * la transacción de quien decide, con la fila bloqueada.
 */
final class FineImportDecisions
{
    /** @throws ValidationException */
    public function record(
        FineImportRow $fila,
        FineImportIssue $problema,
        FineImportAction $accion,
        ?string $reason,
        ?int $actorId,
        ?int $fineCaseId,
    ): FineImportResolution {
        if ($fila->fine_id !== null) {
            throw ValidationException::withMessages(['action' => 'La fila ya se consolidó.']);
        }

        if (! in_array($problema, $fila->blockingIssues(), true)) {
            throw ValidationException::withMessages(['action' => 'La fila no tiene ese problema.']);
        }

        if (! in_array($accion, $problema->actions(), true)) {
            throw ValidationException::withMessages(['action' => 'Ese problema no se resuelve así.']);
        }

        if ($fila->resolutions()->where('issue_code', $problema->value)->exists()) {
            throw ValidationException::withMessages(['action' => 'Ese problema ya se resolvió.']);
        }

        $meta = $this->meta($fila, $problema);
        $motivo = trim((string) $reason);
        $fineId = null;

        if ($accion === FineImportAction::Link) {
            $fineId = (int) ($meta['fine_id'] ?? 0);
        }

        if ($accion === FineImportAction::SameCase) {
            $candidatos = array_column($meta['cases'] ?? [], 'id');

            if ($fineCaseId === null || ! in_array($fineCaseId, $candidatos, true)) {
                throw ValidationException::withMessages(['fineCaseId' => 'Elegí uno de los expedientes que coinciden.']);
            }
        }

        if ($accion === FineImportAction::AddMonths && ($meta['only_new_months'] ?? false) !== true) {
            throw ValidationException::withMessages(['action' => 'Cambió algo más que los meses nuevos: corregilo en la multa y excluí la fila.']);
        }

        if ($accion === FineImportAction::Exclude && $motivo === '') {
            throw ValidationException::withMessages(['reason' => 'Contá por qué se excluye.']);
        }

        return FineImportResolution::query()->create([
            'fine_import_row_id' => $fila->id,
            'issue_code' => $problema,
            'action' => $accion,
            'fine_case_id' => $accion === FineImportAction::SameCase ? $fineCaseId : null,
            'fine_id' => $fineId,
            'reason' => $motivo === '' ? null : mb_substr($motivo, 0, 500),
            'resolved_by' => $actorId,
            'resolved_at' => now(),
        ]);
    }

    /** @throws ValidationException */
    public function reviewableBatch(int $id): FineImportBatch
    {
        $lote = FineImportBatch::query()->whereKey($id)->lockForUpdate()->firstOrFail();

        if (! in_array($lote->status, [FineImportStatus::Ready, FineImportStatus::Consolidated], true)) {
            throw ValidationException::withMessages(['action' => 'El lote se está leyendo o consolidando: esperá a que termine.']);
        }

        return $lote;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(FineImportRow $fila, FineImportIssue $problema): array
    {
        foreach ($fila->issues as $encontrado) {
            if ($encontrado['code'] === $problema->value) {
                return $encontrado['meta'] ?? [];
            }
        }

        return [];
    }
}
