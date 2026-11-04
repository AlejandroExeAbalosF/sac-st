<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Audit\Subjects;

use App\Models\User;
use App\Modules\Aranceles\Models\FeeCharge;
use App\Modules\Shared\Audit\AuditSubjectDescription;
use App\Modules\Shared\Audit\BaseAuditSubjectResolver;

final class FeeChargeSubject extends BaseAuditSubjectResolver
{
    public function subjectType(): string
    {
        return 'FeeCharge';
    }

    public function label(): string
    {
        return 'Cobro de arancel';
    }

    public function fields(): array
    {
        return [];
    }

    public function describe(array $ids, ?User $viewer): array
    {
        $puede = $this->can($viewer, 'aranceles.ver');
        $descripciones = [];

        foreach (FeeCharge::query()->whereIn('id', $ids)->get(['id', 'payer_name']) as $cobro) {
            $descripciones[$cobro->id] = new AuditSubjectDescription(
                $cobro->payer_name,
                $puede ? route('aranceles.cobros.index', ['cobro' => $cobro->id]) : null,
            );
        }

        return $descripciones;
    }
}
