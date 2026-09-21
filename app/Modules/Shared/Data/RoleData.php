<?php

declare(strict_types=1);

namespace App\Modules\Shared\Data;

use App\Modules\Shared\Enums\SystemRole;
use Spatie\LaravelData\Data;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un rol con los permisos que tiene puestos hoy.
 *
 * `editable` en `false` es el administrador: la pantalla lo muestra igual,
 * porque ocultarlo haría pensar que no existe, pero con sus controles
 * trabados y la leyenda de por qué.
 */
#[TypeScript]
final class RoleData extends Data
{
    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $description,
        public array $permissions,
        public bool $editable,
        public int $usersCount,
    ) {}

    public static function fromModel(Role $role, int $usersCount): self
    {
        $systemRole = SystemRole::tryFrom($role->name);

        return new self(
            id: (int) $role->getKey(),
            name: $role->name,
            description: $systemRole?->description() ?? '',
            permissions: array_values(
                $role->permissions
                    ->map(fn (Permission $permission): string => $permission->name)
                    ->sort()
                    ->all()
            ),
            editable: $systemRole?->permissionsAreEditable() ?? true,
            usersCount: $usersCount,
        );
    }
}
