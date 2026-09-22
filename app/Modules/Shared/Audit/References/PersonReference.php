<?php

declare(strict_types=1);

namespace App\Modules\Shared\Audit\References;

use App\Modules\Shared\Audit\AuditReferenceResolver;
use App\Modules\Shared\Models\Person;

/**
 * El nombre de las personas que un evento menciona por id.
 *
 * El registro guarda el id y hace bien: la razón social puede corregirse
 * y el historial tiene que seguir señalando a la misma persona. Un número
 * en pantalla, en cambio, no le dice nada a nadie.
 */
final class PersonReference implements AuditReferenceResolver
{
    public function fields(): array
    {
        return ['beneficiary_id', 'employer_id', 'owner_person_id', 'depositor_id'];
    }

    public function labels(array $ids): array
    {
        /** @var array<int, string> $nombres */
        $nombres = Person::query()->whereIn('id', $ids)->pluck('name', 'id')->all();

        return $nombres;
    }
}
