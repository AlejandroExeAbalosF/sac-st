<?php

declare(strict_types=1);

namespace Tests\Feature\Configuracion;

use App\Models\User;
use App\Modules\Shared\Models\AuditEvent;
use App\Modules\Shared\Permissions\PermissionCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    private function administrador(): User
    {
        $user = User::factory()->create();
        $user->assignRole('administrador');

        return $user;
    }

    public function test_se_pueden_editar_los_permisos_de_un_rol(): void
    {
        $contador = Role::findByName('contador', 'web');

        $this
            ->actingAs($this->administrador())
            ->from(route('configuracion.roles.index'))
            ->put(route('configuracion.roles.update', $contador), [
                'permissions' => ['caja.ver', 'cierres.ver'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['caja.ver', 'cierres.ver'],
            $contador->fresh()->permissions()->orderBy('name')->pluck('name')->all(),
        );

        $this->assertTrue(
            AuditEvent::query()
                ->where('action', 'rol.permisos_actualizados')
                ->where('subject_type', 'Role')
                ->where('subject_id', $contador->id)
                ->exists()
        );
    }

    public function test_el_rol_administrador_no_se_edita(): void
    {
        $administrador = Role::findByName('administrador', 'web');

        $this
            ->actingAs($this->administrador())
            ->from(route('configuracion.roles.index'))
            ->put(route('configuracion.roles.update', $administrador), [
                'permissions' => [],
            ])
            ->assertSessionHasErrors('permissions');
    }

    /**
     * La pantalla traba al super-admin igual que al administrador, pero el
     * servidor solo protegía al segundo: un PUT directo le cambiaba la
     * matriz y dejaba un evento crítico por un cambio sin ningún efecto.
     */
    public function test_el_rol_super_admin_tampoco_se_edita(): void
    {
        $superAdmin = Role::findByName('super-admin', 'web');
        $antes = $superAdmin->permissions()->orderBy('name')->pluck('name')->all();

        $this
            ->actingAs($this->administrador())
            ->from(route('configuracion.roles.index'))
            ->put(route('configuracion.roles.update', $superAdmin), [
                'permissions' => [],
            ])
            ->assertSessionHasErrors([
                'permissions' => 'El rol super-admin recibe todos los permisos por diseño y no se edita.',
            ]);

        $this->assertSame($antes, $superAdmin->fresh()->permissions()->orderBy('name')->pluck('name')->all());
        $this->assertFalse(
            AuditEvent::query()->where('action', 'rol.permisos_actualizados')->exists()
        );
    }

    public function test_la_pantalla_explica_cada_permiso_y_ordena_por_circuito(): void
    {
        $this
            ->actingAs($this->administrador())
            ->get(route('configuracion.roles.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('configuracion/roles')
                ->where('permissionGroups.0.key', 'expedientes')
                ->where('permissionGroups.0.label', 'Expedientes')
                ->where('permissionGroups.0.permissions.0.name', 'expedientes.ver')
                ->where('permissionGroups.0.permissions.0.label', 'Ver expedientes')
                ->where('permissionGroups.0.permissions.0.sensitive', false)
                ->has('permissionGroups.0.permissions.0.description')
                ->where('roles.0.name', 'administrador')
                ->where('roles.0.editable', false)
                ->where('roles.0.lockedReason', fn (string $motivo): bool => str_contains($motivo, 'administrador'))
                ->where('roles.1.name', 'contador')
                ->where('roles.1.lockedReason', null)
                ->where('roles.4.name', 'super-admin')
                ->where('roles.4.lockedReason', fn (string $motivo): bool => str_contains($motivo, 'super-admin'))
            );
    }

    public function test_cada_permiso_de_la_pantalla_tiene_rotulo_y_descripcion(): void
    {
        $this
            ->actingAs($this->administrador())
            ->get(route('configuracion.roles.index'))
            ->assertInertia(function (AssertableInertia $page): void {
                $permisos = collect($page->toArray()['props']['permissionGroups'])->flatMap(
                    fn (array $grupo): array => $grupo['permissions'],
                );

                $this->assertSame(
                    Permission::query()->where('name', 'not like', 'dev.%')->count(),
                    $permisos->count(),
                    'La pantalla tiene que ofrecer todos los permisos sembrados que se pueden otorgar.',
                );

                foreach ($permisos as $permiso) {
                    $this->assertNotSame('', trim($permiso['label']), $permiso['name']);
                    $this->assertNotSame('', trim($permiso['description']), $permiso['name']);
                }

                $this->assertFalse($permisos->firstWhere('name', 'roles.gestionar')['delegable']);
                $this->assertTrue($permisos->firstWhere('name', 'caja.ver')['delegable']);
                $this->assertFalse($permisos->firstWhere('name', 'usuarios.crear')['delegable']);
                // Ver la lista de usuarios es solo lectura: sí se delega.
                $this->assertTrue($permisos->firstWhere('name', 'usuarios.ver')['delegable']);
            });
    }

    public function test_el_super_admin_entra_a_la_pantalla_de_roles(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');

        $this->actingAs($superAdmin)
            ->get(route('configuracion.roles.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('configuracion/roles')
                ->where('auth.can', fn ($can): bool => collect($can)->get('roles.gestionar') === true));
    }

    /**
     * Quien edita la matriz puede editar la de su propio rol: con
     * `roles.gestionar` un contador se daría cualquier otro permiso.
     */
    /**
     * Con `usuarios.crear` un contador daba de alta una cuenta con rol
     * administrador y entraba con la clave temporal.
     */
    public function test_la_administracion_de_usuarios_no_se_delega(): void
    {
        $contador = Role::findByName('contador', 'web');

        $this
            ->actingAs($this->administrador())
            ->from(route('configuracion.roles.index'))
            ->put(route('configuracion.roles.update', $contador), [
                'permissions' => ['usuarios.ver', 'usuarios.crear', 'usuarios.restablecer-password'],
            ])
            ->assertSessionHasErrors([
                'permissions' => 'Estos permisos no se delegan: los tiene solo el administrador (usuarios.crear, usuarios.restablecer-password).',
            ]);

        $this->assertFalse($contador->fresh()->hasPermissionTo('usuarios.crear'));
    }

    public function test_ver_usuarios_si_se_delega(): void
    {
        $contador = Role::findByName('contador', 'web');

        $this
            ->actingAs($this->administrador())
            ->from(route('configuracion.roles.index'))
            ->put(route('configuracion.roles.update', $contador), [
                'permissions' => ['usuarios.ver'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($contador->fresh()->hasPermissionTo('usuarios.ver'));
    }

    public function test_roles_gestionar_no_se_delega(): void
    {
        $contador = Role::findByName('contador', 'web');

        $this
            ->actingAs($this->administrador())
            ->from(route('configuracion.roles.index'))
            ->put(route('configuracion.roles.update', $contador), [
                'permissions' => ['caja.ver', 'roles.gestionar'],
            ])
            ->assertSessionHasErrors([
                'permissions' => 'Estos permisos no se delegan: los tiene solo el administrador (roles.gestionar).',
            ]);

        $this->assertFalse($contador->fresh()->hasPermissionTo('roles.gestionar'));
    }

    /**
     * Y la base lo impide para cada permiso que el catálogo declara no
     * delegable: el trigger tiene la lista escrita en SQL, y esto es lo
     * que avisa si el catálogo suma uno que el trigger no cubre.
     */
    public function test_la_base_rechaza_los_permisos_no_delegables_para_un_rol_de_la_matriz(): void
    {
        $noDelegables = app(PermissionCatalog::class)->nonDelegable();
        $this->assertNotEmpty($noDelegables);

        $contador = Role::findByName('contador', 'web');

        foreach ($noDelegables as $nombre) {
            $rechazado = false;

            try {
                // La transacción anidada abre un savepoint: el error no
                // aborta la transacción del test.
                DB::transaction(fn () => DB::table('role_has_permissions')->insert([
                    'permission_id' => Permission::findByName($nombre, 'web')->id,
                    'role_id' => $contador->id,
                ]));
            } catch (QueryException $e) {
                $rechazado = str_contains($e->getMessage(), 'no se delega');
            }

            $this->assertTrue($rechazado, "La base dejó otorgarle «{$nombre}» al contador.");
        }
    }

    /**
     * Si un rol de la matriz ya lo tenía, la migración no se lo quita en
     * silencio: frena y pide sacarlo desde la pantalla, que lo audita.
     */
    public function test_la_migracion_frena_si_un_rol_de_la_matriz_ya_lo_tiene(): void
    {
        DB::statement('DROP TRIGGER role_has_permissions_guard_non_delegable ON role_has_permissions');
        DB::table('role_has_permissions')->insert([
            'permission_id' => Permission::findByName('roles.gestionar', 'web')->id,
            'role_id' => Role::findByName('contador', 'web')->id,
        ]);

        $migracion = require database_path('migrations/2026_10_12_010000_guard_non_delegable_permissions.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('contador (roles.gestionar)');

        $migracion->up();
    }

    public function test_no_se_puede_otorgar_un_permiso_inexistente(): void
    {
        $consulta = Role::findByName('consulta', 'web');

        $this
            ->actingAs($this->administrador())
            ->from(route('configuracion.roles.index'))
            ->put(route('configuracion.roles.update', $consulta), [
                'permissions' => ['permiso.inventado'],
            ])
            ->assertSessionHasErrors('permissions.0');
    }

    public function test_un_contador_no_llega_a_la_pantalla_de_roles(): void
    {
        $user = User::factory()->create();
        $user->assignRole('contador');

        $this->actingAs($user)
            ->get(route('configuracion.roles.index'))
            ->assertForbidden();
    }

    /**
     * La razón de ser del rediseño del seeder: antes hacía `syncPermissions`
     * incondicional, y el próximo `db:seed` borraba en silencio lo que el
     * área había configurado desde la pantalla.
     */
    public function test_volver_a_sembrar_no_pisa_los_permisos_configurados(): void
    {
        $contador = Role::findByName('contador', 'web');
        $contador->syncPermissions(['caja.ver']);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(
            ['caja.ver'],
            $contador->fresh()->permissions()->pluck('name')->all(),
        );
    }

    /**
     * Un permiso que nace con el despliegue llega con su asignación de
     * fábrica aunque el rol ya esté configurado; uno que el área le quitó al
     * rol, y que ya existía, sigue quitado.
     */
    public function test_un_permiso_nuevo_llega_a_los_roles_ya_configurados(): void
    {
        $contador = Role::findByName('contador', 'web');
        $contador->syncPermissions(['caja.ver']);
        Permission::findByName('multas.ajustar', 'web')->delete();

        $this->seed(RolesAndPermissionsSeeder::class);

        $permisos = $contador->fresh()->permissions()->pluck('name');

        $this->assertTrue($permisos->contains('multas.ajustar'));
        $this->assertTrue($permisos->contains('caja.ver'));
        // `multas.ver` ya existía y el área no se lo había dado: no se toca.
        $this->assertFalse($permisos->contains('multas.ver'));
        // Ni lo que la matriz de fábrica no le da a ese rol.
        $this->assertFalse(Role::findByName('consulta', 'web')->permissions()->pluck('name')->contains('multas.ajustar'));
    }

    public function test_sembrar_agrega_los_permisos_nuevos_al_catalogo(): void
    {
        // Un permiso que quedó de una versión anterior del mapa desaparece;
        // los del mapa vigente están todos.
        Permission::findOrCreate('permiso.retirado', 'web');

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertDatabaseMissing('permissions', ['name' => 'permiso.retirado']);
        $this->assertDatabaseHas('permissions', ['name' => 'usuarios.crear']);
    }

    public function test_el_comando_devuelve_los_roles_a_la_matriz_de_fabrica(): void
    {
        $contador = Role::findByName('contador', 'web');
        $contador->syncPermissions(['caja.ver']);

        $this->artisan('roles:restaurar-predeterminados', ['--force' => true])
            ->assertSuccessful();

        $permisos = $contador->fresh()->permissions()->pluck('name');

        $this->assertTrue($permisos->contains('egresos.validar'));
        $this->assertTrue($permisos->contains('cierres.cerrar'));
        $this->assertGreaterThan(1, $permisos->count());
    }

    /**
     * `dev.forzar-cbu` en manos de un contador es una Orden de Pago a un
     * CBU sin verificar. Las capacidades de desarrollo no se otorgan.
     */
    public function test_las_capacidades_de_desarrollo_no_se_otorgan(): void
    {
        $contador = Role::findByName('contador', 'web');

        $this
            ->actingAs($this->administrador())
            ->from(route('configuracion.roles.index'))
            ->put(route('configuracion.roles.update', $contador), [
                'permissions' => ['dev.forzar-cbu'],
            ])
            ->assertSessionHasErrors([
                'permissions' => 'Las capacidades de desarrollo (dev.forzar-cbu) no se otorgan a ningún rol.',
            ]);

        $this->assertFalse($contador->refresh()->hasPermissionTo('dev.forzar-cbu'));
    }

    /** Y la base lo impide aunque se escriba en la tabla por otro camino. */
    public function test_la_base_rechaza_dev_para_otro_rol_que_super_admin(): void
    {
        $contador = Role::findByName('contador', 'web');
        $permiso = Permission::findByName('dev.forzar-cbu', 'web');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('solo las tiene el rol super-admin');

        DB::table('role_has_permissions')->insert([
            'permission_id' => $permiso->id,
            'role_id' => $contador->id,
        ]);
    }

    public function test_la_pantalla_no_ofrece_las_capacidades_de_desarrollo(): void
    {
        $this
            ->actingAs($this->administrador())
            ->get(route('configuracion.roles.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $grupos = json_encode($page->toArray()['props']['permissionGroups']);

                $this->assertIsString($grupos);
                $this->assertStringNotContainsString('dev.', $grupos);
            });
    }
}
