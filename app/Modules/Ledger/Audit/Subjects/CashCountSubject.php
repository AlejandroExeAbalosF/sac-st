<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Audit\Subjects;

use App\Models\User;
use App\Modules\Ledger\Enums\CashCountStatus;
use App\Modules\Ledger\Models\CashCount;
use App\Modules\Shared\Audit\AuditSubjectDescription;
use App\Modules\Shared\Audit\BaseAuditSubjectResolver;
use App\Support\EnumLabels;

final class CashCountSubject extends BaseAuditSubjectResolver
{
    public function subjectType(): string
    {
        return 'CashCount';
    }

    public function label(): string
    {
        return 'Arqueo';
    }

    public function fields(): array
    {
        return [
            'counted_amount' => 'Contado',
            'uncounted_amount' => 'Sin contar',
            'expected_amount' => 'Esperado',
            'difference_amount' => 'Diferencia',
            'self_reviewed' => 'Revisado por quien contó',
            'adjustment_event_id' => 'Asiento de ajuste',
            'status' => 'Estado',
        ];
    }

    public function valueLabels(): array
    {
        return ['status' => EnumLabels::of(CashCountStatus::class)];
    }

    public function describe(array $ids, ?User $viewer): array
    {
        $puedeVer = $this->can($viewer, 'caja.ver');

        $descripciones = [];

        foreach (CashCount::query()->whereIn('id', $ids)->get(['id', 'counted_on', 'sequence']) as $arqueo) {
            $dia = $arqueo->counted_on->format('d/m/Y');

            $descripciones[$arqueo->id] = new AuditSubjectDescription(
                "Arqueo del {$dia}".($arqueo->sequence > 1 ? " (n.º {$arqueo->sequence})" : ''),
                $puedeVer ? route('caja.arqueos.index', ['fecha' => $arqueo->counted_on->toDateString()]) : null,
            );
        }

        return $descripciones;
    }
}
