<?php

declare(strict_types=1);

namespace App\Modules\Multas\Audit\Subjects;

use App\Models\User;
use App\Modules\Multas\Models\FineCase;
use App\Modules\Shared\Audit\AuditSubjectDescription;
use App\Modules\Shared\Audit\BaseAuditSubjectResolver;

final class FineCaseSubject extends BaseAuditSubjectResolver
{
    public function subjectType(): string
    {
        return 'FineCase';
    }

    public function label(): string
    {
        return 'Expediente de multa';
    }

    public function fields(): array
    {
        return [
            'original_text' => 'Número en el papel',
            'number' => 'Número',
            'year' => 'Año',
            'suffix' => 'Sufijo',
        ];
    }

    public function describe(array $ids, ?User $viewer): array
    {
        $descripciones = [];

        foreach (FineCase::query()->whereIn('id', $ids)->get(['id', 'original_text', 'number', 'year', 'suffix']) as $expediente) {
            $descripciones[$expediente->id] = new AuditSubjectDescription(
                'Expediente '.$expediente->label(),
                $this->can($viewer, 'multas.ver') ? route('multas.expedientes.show', $expediente->routeParameters()) : null,
            );
        }

        return $descripciones;
    }
}
