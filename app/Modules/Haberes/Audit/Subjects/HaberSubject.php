<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Audit\Subjects;

use App\Models\User;
use App\Modules\Haberes\Audit\InstallmentLinks;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Shared\Audit\AuditSubjectDescription;
use App\Modules\Shared\Audit\BaseAuditSubjectResolver;
use App\Support\EnumLabels;

final class HaberSubject extends BaseAuditSubjectResolver
{
    public function __construct(private readonly InstallmentLinks $links) {}

    public function subjectType(): string
    {
        return 'Haber';
    }

    public function label(): string
    {
        return 'Haber';
    }

    public function fields(): array
    {
        return [
            'beneficiary_id' => 'Beneficiario',
            'assigned_amount' => 'Importe reconocido',
            'expected_installment_count' => 'Cuotas previstas',
            'workflow_status' => 'Estado',
            'block_reason' => 'Motivo del bloqueo',
            'concept' => 'Concepto',
            'notes' => 'Observaciones',
        ];
    }

    public function valueLabels(): array
    {
        return ['workflow_status' => EnumLabels::of(HaberWorkflowStatus::class)];
    }

    public function describe(array $ids, ?User $viewer): array
    {
        $haberes = Haber::query()
            ->with(['expediente:id,display_number', 'beneficiary:id,name'])
            ->whereIn('id', $ids)
            ->get(['id', 'expediente_id', 'haber_number', 'beneficiary_id']);

        $descripciones = [];

        foreach ($haberes as $haber) {
            $descripciones[$haber->id] = new AuditSubjectDescription(
                $this->links->haberContext($haber)." · {$haber->beneficiary->name}",
                $this->links->haberUrl($haber, $viewer),
            );
        }

        return $descripciones;
    }
}
