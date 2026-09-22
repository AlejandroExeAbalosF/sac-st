<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Audit\Subjects;

use App\Models\User;
use App\Modules\Ledger\Enums\PeriodClosingStatus;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Shared\Audit\AuditSubjectDescription;
use App\Modules\Shared\Audit\BaseAuditSubjectResolver;
use App\Support\EnumLabels;

final class PeriodClosingSubject extends BaseAuditSubjectResolver
{
    public function subjectType(): string
    {
        return 'PeriodClosing';
    }

    public function label(): string
    {
        return 'Cierre de caja';
    }

    public function fields(): array
    {
        return [
            'closing_cash' => 'Efectivo al cierre',
            'closing_cheques' => 'Cheques al cierre',
            'closing_bank_deposits' => 'Depósitos al cierre',
            'status' => 'Estado',
            'sheet_attachment_id' => 'Planilla',
        ];
    }

    public function valueLabels(): array
    {
        return ['status' => EnumLabels::of(PeriodClosingStatus::class)];
    }

    public function moneyFields(): array
    {
        return ['closing_cash', 'closing_cheques', 'closing_bank_deposits'];
    }

    public function describe(array $ids, ?User $viewer): array
    {
        $puedeVer = $this->can($viewer, 'cierres.ver');

        $descripciones = [];

        foreach (PeriodClosing::query()->whereIn('id', $ids)->get(['id', 'period_from', 'period_to']) as $cierre) {
            $desde = $cierre->period_from->format('d/m/Y');
            $hasta = $cierre->period_to->format('d/m/Y');

            $descripciones[$cierre->id] = new AuditSubjectDescription(
                $desde === $hasta ? "Cierre del {$desde}" : "Cierre del {$desde} al {$hasta}",
                $puedeVer ? route('caja.cierres.index', ['fecha' => $cierre->period_to->toDateString()]) : null,
            );
        }

        return $descripciones;
    }
}
