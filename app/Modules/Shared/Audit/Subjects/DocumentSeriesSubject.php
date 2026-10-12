<?php

declare(strict_types=1);

namespace App\Modules\Shared\Audit\Subjects;

use App\Models\User;
use App\Modules\Shared\Audit\AuditSubjectDescription;
use App\Modules\Shared\Audit\BaseAuditSubjectResolver;
use App\Modules\Shared\Models\DocumentSeries;

final class DocumentSeriesSubject extends BaseAuditSubjectResolver
{
    public function subjectType(): string
    {
        return 'DocumentSeries';
    }

    public function label(): string
    {
        return 'Numeración de comprobantes';
    }

    public function fields(): array
    {
        return ['display_prefix' => 'Prefijo', 'code_padding' => 'Dígitos del prefijo', 'number_padding' => 'Dígitos del número', 'format_pattern' => 'Formato', 'next_number' => 'Próximo número'];
    }

    public function describe(array $ids, ?User $viewer): array
    {
        $result = [];
        foreach (DocumentSeries::query()->whereIn('id', $ids)->get() as $series) {
            $result[$series->id] = new AuditSubjectDescription($series->document_type->label(),
                $this->can($viewer, 'numeracion.gestionar') ? route('configuracion.numeracion.index') : null);
        }

        return $result;
    }
}
