<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Audit\Subjects;

use App\Models\User;
use App\Modules\Haberes\Audit\InstallmentLinks;
use App\Modules\Haberes\Enums\ExpedienteStatus;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Shared\Audit\AuditSubjectDescription;
use App\Modules\Shared\Audit\BaseAuditSubjectResolver;
use App\Support\EnumLabels;

final class ExpedienteSubject extends BaseAuditSubjectResolver
{
    public function __construct(private readonly InstallmentLinks $links) {}

    public function subjectType(): string
    {
        return 'Expediente';
    }

    public function label(): string
    {
        return 'Expediente';
    }

    /**
     * Los nombres de los campos como los ve el operador.
     *
     * La columna se llama `declared_total_amount` y en la pantalla dice
     * «Total declarado». Mostrar el nombre técnico obligaría a traducir de
     * memoria justo cuando alguien trata de entender qué pasó.
     */
    public function fields(): array
    {
        return [
            'subject' => 'Carátula',
            'received_date' => 'Fecha de recepción',
            'employer_id' => 'Empleador',
            'employer_representative' => 'Representante',
            'declared_total_amount' => 'Total declarado',
            'external_reference' => 'Referencia externa',
            'notes' => 'Observaciones',
            'status' => 'Estado',
            'canonical_number' => 'Número de SiCE',
        ];
    }

    public function valueLabels(): array
    {
        return ['status' => EnumLabels::of(ExpedienteStatus::class)];
    }

    public function describe(array $ids, ?User $viewer): array
    {
        $descripciones = [];

        foreach (Expediente::query()->whereIn('id', $ids)->get(['id', 'display_number']) as $expediente) {
            $descripciones[$expediente->id] = new AuditSubjectDescription(
                $this->links->expedienteLabel($expediente),
                $this->links->expedienteUrl($expediente, $viewer),
            );
        }

        return $descripciones;
    }
}
