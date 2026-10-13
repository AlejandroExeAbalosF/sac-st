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
 * `editable` en `false` es el administrador o el super-admin: la pantalla
 * los muestra igual, porque ocultarlos haría pensar que no existen, pero con
 * sus controles trabados y `lockedReason` diciendo por qué.
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
        public ?string $lockedReason,
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
            lockedReason: $systemRole?->lockedReason(),
            usersCount: $usersCount,
        );
    }
}
