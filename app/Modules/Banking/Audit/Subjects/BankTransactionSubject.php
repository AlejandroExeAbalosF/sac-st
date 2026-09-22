<?php

declare(strict_types=1);

namespace App\Modules\Banking\Audit\Subjects;

use App\Models\User;
use App\Modules\Banking\Audit\References\BankTransactionReference;
use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Shared\Audit\AuditSubjectDescription;
use App\Modules\Shared\Audit\BaseAuditSubjectResolver;
use App\Support\EnumLabels;

final class BankTransactionSubject extends BaseAuditSubjectResolver
{
    public function subjectType(): string
    {
        return 'BankTransaction';
    }

    public function label(): string
    {
        return 'Movimiento bancario';
    }

    public function fields(): array
    {
        return [
            'reconciliation_status' => 'Estado',
            'ignored_reason' => 'Motivo',
        ];
    }

    public function valueLabels(): array
    {
        return ['reconciliation_status' => EnumLabels::of(ReconciliationStatus::class)];
    }

    public function describe(array $ids, ?User $viewer): array
    {
        $puedeVer = $this->can($viewer, 'banco.extractos.ver');

        $descripciones = [];

        foreach (BankTransaction::query()->whereIn('id', $ids)->get(['id', 'operation_id']) as $movimiento) {
            $descripciones[$movimiento->id] = new AuditSubjectDescription(
                BankTransactionReference::labelFor($movimiento),
                // El listado de movimientos busca por número de operación;
                // sin número no hay cómo llegar a uno solo.
                $puedeVer && $movimiento->operation_id !== null
                    ? route('banco.movimientos.index', ['buscar' => $movimiento->operation_id])
                    : null,
            );
        }

        return $descripciones;
    }
}
