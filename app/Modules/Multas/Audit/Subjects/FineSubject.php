<?php

declare(strict_types=1);

namespace App\Modules\Multas\Audit\Subjects;

use App\Models\User;
use App\Modules\Multas\Enums\DatePrecision;
use App\Modules\Multas\Models\Fine;
use App\Modules\Shared\Audit\AuditSubjectDescription;
use App\Modules\Shared\Audit\BaseAuditSubjectResolver;
use App\Support\EnumLabels;

final class FineSubject extends BaseAuditSubjectResolver
{
    public function subjectType(): string
    {
        return 'Fine';
    }

    public function label(): string
    {
        return 'Multa';
    }

    public function fields(): array
    {
        return [
            'caption' => 'Carátula',
            'person_id' => 'Persona',
            'registered_on' => 'Fecha de registro',
            'registered_precision' => 'Precisión de la fecha',
            'effective_on' => 'Fecha del movimiento',
            'effective_precision' => 'Precisión de la fecha del movimiento',
            'occurred_on' => 'Fecha del pago anterior',
            'occurred_precision' => 'Precisión de la fecha del pago anterior',
            'obligation' => 'Obligación',
            'amount' => 'Importe original',
        ];
    }

    public function valueLabels(): array
    {
        $precision = EnumLabels::of(DatePrecision::class);

        return [
            'registered_precision' => $precision,
            'effective_precision' => $precision,
            'occurred_precision' => $precision,
        ];
    }

    public function moneyFields(): array
    {
        return ['obligation', 'amount'];
    }

    public function describe(array $ids, ?User $viewer): array
    {
        $multas = Fine::query()
            ->with('fineCase:id,original_text,number,year,suffix')
            ->whereIn('id', $ids)
            ->get(['id', 'fine_case_id', 'caption']);

        $descripciones = [];

        foreach ($multas as $multa) {
            $descripciones[$multa->id] = new AuditSubjectDescription(
                "Multa · Expte. {$multa->fineCase->label()} · {$multa->caption}",
                $this->can($viewer, 'multas.ver') ? route('multas.show', $multa->id) : null,
            );
        }

        return $descripciones;
    }
}
