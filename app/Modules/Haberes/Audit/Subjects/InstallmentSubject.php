<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Audit\Subjects;

use App\Models\User;
use App\Modules\Haberes\Audit\InstallmentLinks;
use App\Modules\Shared\Audit\AuditSubjectDescription;
use App\Modules\Shared\Audit\BaseAuditSubjectResolver;

final class InstallmentSubject extends BaseAuditSubjectResolver
{
    public function __construct(private readonly InstallmentLinks $links) {}

    public function subjectType(): string
    {
        return 'BeneficiaryInstallment';
    }

    public function label(): string
    {
        return 'Cuota';
    }

    public function fields(): array
    {
        return [
            'expected_amount' => 'Importe',
            'management_label_id' => 'Etiqueta de gestión',
            'due_date' => 'Vencimiento',
            'description' => 'Concepto',
            'expected_medium' => 'Medio previsto',
            'notes' => 'Observaciones',
            'workflow_status' => 'Estado',
            'block_reason' => 'Motivo del bloqueo',
            'installment_number' => 'Número de cuota',
            // De la habilitación de la edición con la orden emitida.
            'reason' => 'Motivo',
            'payment_order_number' => 'Orden de pago',
            'payment_order_id' => 'Orden de pago (id interno)',
        ];
    }

    public function valueLabels(): array
    {
        return [
            'expected_medium' => [
                'cash' => 'Efectivo',
                'cheque' => 'Cheque',
                'bank' => 'Depósito en cuenta',
            ],
        ];
    }

    public function describe(array $ids, ?User $viewer): array
    {
        $descripciones = [];

        foreach ($this->links->load($ids) as $id => $cuota) {
            $descripciones[$id] = new AuditSubjectDescription(
                $this->links->context($cuota),
                $this->links->url($cuota, $viewer),
            );
        }

        return $descripciones;
    }
}
