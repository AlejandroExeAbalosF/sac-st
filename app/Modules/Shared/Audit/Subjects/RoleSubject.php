<?php

declare(strict_types=1);

namespace App\Modules\Shared\Audit\Subjects;

use App\Models\User;
use App\Modules\Shared\Audit\AuditSubjectDescription;
use App\Modules\Shared\Audit\BaseAuditSubjectResolver;
use Spatie\Permission\Models\Role;

final class RoleSubject extends BaseAuditSubjectResolver
{
    public function subjectType(): string
    {
        return 'Role';
    }

    public function label(): string
    {
        return 'Rol';
    }

    public function fields(): array
    {
        return ['permissions' => 'Permisos'];
    }

    public function describe(array $ids, ?User $viewer): array
    {
        // La pantalla de roles los muestra todos juntos: no hay adónde
        // llevar a uno en particular, pero sí a la matriz donde está.
        $url = $this->can($viewer, 'roles.gestionar') ? route('configuracion.roles.index') : null;

        $descripciones = [];

        foreach (Role::query()->whereIn('id', $ids)->get(['id', 'name']) as $role) {
            $descripciones[(int) $role->getKey()] = new AuditSubjectDescription(
                ucfirst((string) $role->getAttribute('name')),
                $url,
            );
        }

        return $descripciones;
    }
}
