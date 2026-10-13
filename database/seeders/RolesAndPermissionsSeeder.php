<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Shared\Enums\SystemRole;
use App\Modules\Shared\Permissions\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles y permisos del área.
 *
 * Idempotente a propósito: se corre las veces que haga falta y en
 * producción. Esto NO va en una migración —como se hizo en Bitácora—
 * porque un permiso es un dato, no un cambio de esquema: obligar a migrar
 * para agregar un permiso es una fricción que después nadie respeta.
 *
 * Qué permisos existen, qué hace cada uno y a quién se le da de fábrica
 * no se declara acá sino en `PermissionCatalog`, que arma cada módulo con
 * lo suyo. Este seeder siembra exactamente eso: la pantalla de roles y la
 * tabla de permisos leen la misma fuente, y un permiso no puede existir
 * sin su explicación.
 *
 * **Dos responsabilidades distintas, desde que los roles se editan desde
 * Configuración:**
 *
 * 1. *El catálogo sigue siendo declarativo.* Un permiso existe porque hay
 *    una ruta que lo exige, así que el seeder crea los que faltan y borra
 *    los que se retiraron del mapa. Eso no se negocia con nadie.
 * 2. *La asignación es una línea de base, no una imposición.* Se siembra
 *    una sola vez, cuando el rol todavía no tiene ningún permiso. Antes
 *    acá había un `syncPermissions` incondicional, y con la pantalla de
 *    roles en el sistema eso significaba que el próximo `db:seed` borraba
 *    en silencio lo que el área había configurado.
 *
 * Para volver a la matriz de fábrica —a propósito, no de rebote— está
 * `php artisan roles:restaurar-predeterminados`.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function __construct(private readonly PermissionCatalog $catalog) {}

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (SystemRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        foreach ($this->catalog->names() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Un permiso retirado del catálogo desaparece también de los roles que
        // lo tenían: spatie lo saca en cascada al borrar la fila. Es la
        // única forma de que el catálogo converja sin tocar lo que el área
        // configuró sobre los permisos que sí siguen existiendo.
        Permission::query()
            ->where('guard_name', 'web')
            ->whereNotIn('name', $this->catalog->names())
            ->delete();

        // Los permisos recién creados no están en la caché que el registrar
        // levantó al arrancar; sin este olvido, syncPermissions no los
        // encuentra y explota.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (SystemRole::cases() as $role) {
            $model = Role::findByName($role->value, 'web');

            // La línea de base se siembra una sola vez. Un rol que ya tiene
            // permisos fue configurado por alguien —acá la primera vez, o
            // desde la pantalla después— y esa decisión no se pisa.
            if ($model->permissions()->exists()) {
                continue;
            }

            $model->syncPermissions($this->catalog->defaultsFor($role));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Vuelve a dejar cada rol con la matriz del catálogo, descartando lo
     * que se haya configurado desde la pantalla.
     *
     * Lo usa `roles:restaurar-predeterminados`. Es destructivo y por eso
     * tiene su propio comando: nadie lo ejecuta sin haberlo escrito.
     */
    public function restoreDefaults(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (SystemRole::cases() as $role) {
            Role::findByName($role->value, 'web')->syncPermissions($this->catalog->defaultsFor($role));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
