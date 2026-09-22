<?php

declare(strict_types=1);

namespace App\Modules\Shared\Audit\Subjects;

use App\Models\User;
use App\Modules\Shared\Audit\AuditSubjectDescription;
use App\Modules\Shared\Audit\BaseAuditSubjectResolver;
use App\Modules\Shared\Models\Person;

final class PersonSubject extends BaseAuditSubjectResolver
{
    public function subjectType(): string
    {
        return 'Person';
    }

    public function label(): string
    {
        return 'Persona';
    }

    public function fields(): array
    {
        return [
            'first_name' => 'Nombre',
            'last_name' => 'Apellido',
            'legal_name' => 'Razón social',
            'owner_person_id' => 'Titular',
            'document' => 'Documento',
            'tax_identifier' => 'CUIT/CUIL',
            'is_active' => 'Activa',
            'address' => 'Domicilio',
            'phone' => 'Teléfono',
        ];
    }

    public function describe(array $ids, ?User $viewer): array
    {
        $puedeVer = $this->can($viewer, 'personas.ver');

        $descripciones = [];

        foreach (Person::query()->whereIn('id', $ids)->get(['id', 'name', 'document']) as $persona) {
            $descripciones[$persona->id] = new AuditSubjectDescription(
                $persona->name,
                // El maestro no tiene ficha propia: se llega buscándola, y
                // el documento la encuentra sin ambigüedad cuando lo hay.
                $puedeVer ? route('personas.index', ['q' => $persona->document ?? $persona->name]) : null,
            );
        }

        return $descripciones;
    }
}
