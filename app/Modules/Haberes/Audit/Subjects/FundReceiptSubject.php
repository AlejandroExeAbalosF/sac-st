<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Audit\Subjects;

use App\Models\User;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Shared\Audit\AuditSubjectDescription;
use App\Modules\Shared\Audit\BaseAuditSubjectResolver;
use App\Support\EnumLabels;
use App\Support\Money\Decimal;

/**
 * La recepción de fondos. La tabla es de Ledger; la pantalla, de Haberes.
 */
final class FundReceiptSubject extends BaseAuditSubjectResolver
{
    public function subjectType(): string
    {
        return 'FundReceipt';
    }

    public function label(): string
    {
        return 'Recepción de fondos';
    }

    public function fields(): array
    {
        return [
            'medium' => 'Medio',
            'amount' => 'Importe',
            'bank_transaction_id' => 'Movimiento del extracto',
            'financial_event_id' => 'Asiento',
        ];
    }

    public function valueLabels(): array
    {
        return ['medium' => EnumLabels::of(PaymentMedium::class)];
    }

    public function describe(array $ids, ?User $viewer): array
    {
        $puedeVer = $this->can($viewer, 'recepciones.ver');

        $descripciones = [];

        foreach (FundReceipt::query()->whereIn('id', $ids)->get(['id', 'amount']) as $recepcion) {
            $descripciones[$recepcion->id] = new AuditSubjectDescription(
                "Recepción n.º {$recepcion->id} de $ ".Decimal::format((string) $recepcion->amount),
                $puedeVer ? route('recepciones.show', $recepcion->id) : null,
            );
        }

        return $descripciones;
    }
}
