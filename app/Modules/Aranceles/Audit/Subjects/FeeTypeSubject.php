<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Audit\Subjects;

use App\Models\User;
use App\Modules\Aranceles\Models\FeeType;
use App\Modules\Shared\Audit\AuditSubjectDescription;
use App\Modules\Shared\Audit\BaseAuditSubjectResolver;

final class FeeTypeSubject extends BaseAuditSubjectResolver
{
    public function subjectType(): string
    {
        return 'FeeType';
    }

    public function label(): string
    {
        return 'Tipo de arancel';
    }

    public function fields(): array
    {
        return [
            'code' => 'Código',
            'decree_letter' => 'Inciso',
            'name' => 'Concepto',
            'calculation_mode' => 'Modo de cálculo',
            'unit_label' => 'Unidad',
            'is_active' => 'Activo',
        ];
    }

    public function describe(array $ids, ?User $viewer): array
    {
        $url = $this->can($viewer, 'aranceles.ver') ? route('aranceles.tipos.index') : null;

        $descripciones = [];

        foreach (FeeType::query()->whereIn('id', $ids)->get(['id', 'code', 'name']) as $tipo) {
            $descripciones[$tipo->id] = new AuditSubjectDescription("{$tipo->code} · {$tipo->name}", $url);
        }

        return $descripciones;
    }
}
