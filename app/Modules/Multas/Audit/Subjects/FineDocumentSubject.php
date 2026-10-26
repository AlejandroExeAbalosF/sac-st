<?php

declare(strict_types=1);

namespace App\Modules\Multas\Audit\Subjects;

use App\Models\User;
use App\Modules\Multas\Enums\DatePrecision;
use App\Modules\Multas\Enums\FineActType;
use App\Modules\Multas\Enums\FineDocumentKind;
use App\Modules\Multas\Models\FineDocument;
use App\Modules\Shared\Audit\AuditSubjectDescription;
use App\Modules\Shared\Audit\BaseAuditSubjectResolver;
use App\Support\EnumLabels;

final class FineDocumentSubject extends BaseAuditSubjectResolver
{
    public function subjectType(): string
    {
        return 'FineDocument';
    }

    public function label(): string
    {
        return 'Documento de multa';
    }

    public function fields(): array
    {
        return [
            'kind' => 'Tipo de documento',
            'act_type' => 'Tipo de acta',
            'number' => 'Número',
            'year' => 'Año',
            'original_text' => 'Texto del papel',
            'issued_on' => 'Fecha',
            'issued_precision' => 'Precisión de la fecha',
        ];
    }

    public function valueLabels(): array
    {
        return [
            'kind' => EnumLabels::of(FineDocumentKind::class),
            'act_type' => EnumLabels::of(FineActType::class),
            'issued_precision' => EnumLabels::of(DatePrecision::class),
        ];
    }

    /**
     * El enlace lleva al expediente: el documento no tiene pantalla propia.
     */
    public function describe(array $ids, ?User $viewer): array
    {
        $documentos = FineDocument::query()
            ->with('cases:fine_cases.id,fine_cases.original_text,fine_cases.number,fine_cases.year,fine_cases.suffix')
            ->whereIn('id', $ids)
            ->get(['id', 'kind', 'act_type', 'number', 'year', 'original_text']);

        $descripciones = [];

        foreach ($documentos as $documento) {
            $expediente = $documento->cases->first();

            $descripciones[$documento->id] = new AuditSubjectDescription(
                $documento->label(),
                $expediente !== null && $this->can($viewer, 'multas.ver')
                    ? route('multas.expedientes.show', $expediente)
                    : null,
            );
        }

        return $descripciones;
    }
}
