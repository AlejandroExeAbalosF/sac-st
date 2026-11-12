<?php

declare(strict_types=1);

namespace App\Modules\Multas\Audit\Subjects;

use App\Models\User;
use App\Modules\Multas\Models\FineImportBatch;
use App\Modules\Shared\Audit\AuditSubjectDescription;
use App\Modules\Shared\Audit\BaseAuditSubjectResolver;

final class FineImportBatchSubject extends BaseAuditSubjectResolver
{
    public function subjectType(): string
    {
        return 'FineImportBatch';
    }

    public function label(): string
    {
        return 'Importación de la planilla de Multas';
    }

    public function fields(): array
    {
        return [
            'original_filename' => 'Archivo',
            'cutoff_month' => 'Corte (mes de la apertura)',
        ];
    }

    public function describe(array $ids, ?User $viewer): array
    {
        $descripciones = [];

        foreach (FineImportBatch::query()->whereIn('id', $ids)->get(['id', 'original_filename']) as $lote) {
            $descripciones[$lote->id] = new AuditSubjectDescription(
                "Importación N.º {$lote->id} ({$lote->original_filename})",
                $this->can($viewer, 'multas.importar') ? route('multas.importaciones.show', $lote) : null,
            );
        }

        return $descripciones;
    }
}
