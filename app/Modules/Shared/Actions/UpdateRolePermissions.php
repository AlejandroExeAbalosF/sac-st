<?php

declare(strict_types=1);

namespace App\Modules\Shared\Actions;

use App\Modules\Shared\Enums\SystemRole;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cambia los permisos de un rol.
 *
 * `administrador` no se toca: recibe todo por `Gate::before`, sin que se le
 * asigne cada permiso, para que un permiso de una fase futura no le quede
 * afuera por olvido. Dejar que la pantalla le desmarque casilleros daría la
 * falsa impresión de estar restringiéndolo cuando en realidad no cambia
 * nada.
 *
 * El cambio se audita con el diff completo. Quién le dio a un rol la
 * facultad de reabrir un período cerrado es exactamente la clase de
 * pregunta que esta tabla existe para responder.
 */
final class UpdateRolePermissions
{
    public const PROTECTED_ROLE = 'administrador';

    public function __construct(private readonly RecordAuditEvent $auditar) {}

    /**
     * @param  list<string>  $permissions
     */
    public function handle(Role $role, array $permissions): Role
    {
        if ($role->name === self::PROTECTED_ROLE) {
            throw new RuntimeException(
                'El rol administrador recibe todos los permisos por diseño y no se edita.'
            );
        }

        /*
         * Las capacidades `dev.*` son de `super-admin` y de nadie más: el
         * comodín del administrador las deja afuera a propósito (ver
         * `SystemRole::isDeveloperAbility`). Otorgarlas desde esta
         * pantalla era la forma de saltearse ese límite —darle a un
         * contador `dev.forzar-cbu` y emitir una Orden a un CBU sin
         * verificar—. La base lo rechaza igual; esto da el mensaje.
         */
        $desarrollo = array_values(array_filter($permissions, SystemRole::isDeveloperAbility(...)));

        if ($desarrollo !== []) {
            throw new RuntimeException(
                'Las capacidades de desarrollo ('.implode(', ', $desarrollo).') no se otorgan a ningún rol.'
            );
        }

        $before = $role->permissions()->orderBy('name')->pluck('name')->all();

        $role->syncPermissions($permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $after = $role->fresh()?->permissions()->orderBy('name')->pluck('name')->all() ?? [];

        if ($before === $after) {
            return $role;
        }

        $this->auditar->handle(
            action: 'rol.permisos_actualizados',
            subject: $role,
            before: ['permissions' => $before],
            after: ['permissions' => $after],
            metadata: [
                'otorgados' => array_values(array_diff($after, $before)),
                'retirados' => array_values(array_diff($before, $after)),
            ],
        );

        return $role;
    }
}
