<?php

declare(strict_types=1);

namespace App\Modules\Shared\Audit\Subjects;

use App\Models\User;
use App\Modules\Shared\Audit\AuditSubjectDescription;
use App\Modules\Shared\Audit\BaseAuditSubjectResolver;
use App\Modules\Shared\Models\PersonBankAccount;

final class PersonBankAccountSubject extends BaseAuditSubjectResolver
{
    public function subjectType(): string
    {
        return 'PersonBankAccount';
    }

    public function label(): string
    {
        return 'Cuenta bancaria de persona';
    }

    public function fields(): array
    {
        return [
            'cbu' => 'CBU',
            'verification_status' => 'Verificación',
            'rejection_reason' => 'Motivo del rechazo',
            'forced_verification_bypass' => 'Control salteado',
            'forced_verification_reason' => 'Motivo del forzado',
            'is_active' => 'Activa',
        ];
    }

    public function valueLabels(): array
    {
        return [
            'verification_status' => [
                'unverified' => 'Sin verificar',
                'verified' => 'Verificada',
                'rejected' => 'Rechazada',
            ],
        ];
    }

    public function describe(array $ids, ?User $viewer): array
    {
        $puedeVer = $this->can($viewer, 'personas.ver');

        $cuentas = PersonBankAccount::query()
            ->with('person:id,name,document')
            ->whereIn('id', $ids)
            ->get(['id', 'person_id', 'cbu']);

        $descripciones = [];

        foreach ($cuentas as $cuenta) {
            $persona = $cuenta->person;

            $descripciones[$cuenta->id] = new AuditSubjectDescription(
                'CBU terminado en '.substr($cuenta->cbu, -4).($persona === null ? '' : " de {$persona->name}"),
                $puedeVer && $persona !== null
                    ? route('personas.index', ['q' => $persona->document ?? $persona->name])
                    : null,
            );
        }

        return $descripciones;
    }
}
