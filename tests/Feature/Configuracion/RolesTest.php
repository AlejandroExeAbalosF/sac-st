<?php

declare(strict_types=1);

namespace Tests\Feature\Configuracion;

use App\Models\User;
use App\Modules\Shared\Models\AuditEvent;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
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
