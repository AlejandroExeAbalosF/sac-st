<?php

declare(strict_types=1);

namespace App\Modules\Shared\Actions;

use App\Modules\Shared\Enums\SystemRole;
use App\Modules\Shared\Permissions\PermissionCatalog;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cambia los permisos de un rol.
 *
 * `administrador` y `super-admin` no se tocan: reciben todo por
 * `Gate::before`, sin que se les asigne cada permiso, para que un permiso de
 * una fase futura no les quede afuera por olvido. Dejar que se les
 * desmarquen casilleros daría la falsa impresión de estar restringiéndolos
 * cuando en realidad no cambia nada. La regla es la de
 * `SystemRole::permissionsAreEditable()`, la misma que traba la pantalla:
 * antes acá se protegía solo al administrador, y un PUT directo sobre
 * `super-admin` cambiaba su matriz y dejaba un evento de auditoría por un
 * cambio sin efecto.
 *
 * El cambio se audita con el diff completo. Quién le dio a un rol la
 * facultad de reabrir un período cerrado es la clase de
 * pregunta que esta tabla existe para responder.
 */
final class UpdateRolePermissions
{
    public function __construct(
        private readonly RecordAuditEvent $auditar,
        private readonly PermissionCatalog $catalogo,
    ) {}

    /**
     * @param  list<string>  $permissions
     */
    public function handle(Role $role, array $permissions): Role
    {
        if (SystemRole::tryFrom($role->name)?->permissionsAreEditable() === false) {
            throw new RuntimeException(
                "El rol {$role->name} recibe todos los permisos por diseño y no se edita."
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

        /*
         * Lo que no se delega tampoco: `roles.gestionar` y la administración
         * de usuarios. Con cualquiera de ellos un rol de la matriz se daría
         * más poder —su propia matriz, el rol de administrador, la cuenta de
         * un compañero—. La base lo rechaza igual; esto da el mensaje.
         */
        $reservados = array_values(array_intersect($permissions, $this->catalogo->nonDelegable()));

        if ($reservados !== []) {
            throw new RuntimeException(
                'Estos permisos no se delegan: los tiene solo el administrador ('.implode(', ', $reservados).').'
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
