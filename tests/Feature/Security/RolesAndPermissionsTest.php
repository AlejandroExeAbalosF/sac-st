<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolesAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

    }

    /**
     * Los cuatro del area, mas `super-admin`, que no es del area.
     *
     * Se afirma la lista completa y no un `assertContains` por rol: lo que
     * importa es que no aparezca un rol de mas. Un rol nuevo llega con
     * permisos y con gente asignada, asi que tiene que costar agregarlo.
     */
    public function test_the_roles_of_the_area_exist_plus_the_developer_one()
    {
        $this->assertSame(
            ['administrador', 'administrativo', 'consulta', 'contador', 'super-admin'],
            Role::query()->orderBy('name')->pluck('name')->all(),
        );
    }

    /**
     * El super-admin recibe todo, y antes que cualquier otra regla.
     *
     * No es «el administrador con algunos permisos mas»: el comodin le
     * contesta que si a cualquier capacidad, incluidas las que no estan
     * sembradas y las `dev.*` que el administrador no alcanza.
     */
    public function test_the_super_admin_receives_everything_including_developer_abilities()
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $this->assertTrue($user->can('usuarios.crear'));
        $this->assertTrue($user->can('un.permiso.que.todavia.no.existe'));
        $this->assertTrue($user->can('dev.forzar-cbu'));
        $this->assertTrue($user->can('dev.lo-que-se-invente-manana'));
    }

    /**
     * Y es lo unico que el comodin del administrador no cubre.
     *
     * La regla es del prefijo y no de un permiso puntual: agregar manana
     * una pantalla a medio construir no tiene que exigir acordarse de
     * tocar `AppServiceProvider`. Si alguien cambiara el comodin por una
     * lista enumerada, este test avisa.
     */
    public function test_developer_abilities_stay_out_of_the_administrator_wildcard()
    {
        $user = User::factory()->create();
        $user->assignRole('administrador');

        $this->assertFalse($user->can('dev.forzar-cbu'));
        $this->assertFalse($user->can('dev.pantalla-en-construccion'));
    }

    /**
     * El administrador recibe todo por Gate::before, sin que se le asigne
     * cada permiso: así, un permiso nuevo de una fase futura no queda
     * fuera de su alcance por olvido.
     */
    public function test_the_administrator_can_do_everything_without_being_granted_each_permission()
    {
        $user = User::factory()->create();
        $user->assignRole('administrador');

        $this->assertTrue($user->can('usuarios.crear'));
        $this->assertTrue($user->can('un.permiso.que.todavia.no.existe'));
    }

    public function test_the_accountant_reads_the_audit_trail_but_does_not_manage_users()
    {
        $user = User::factory()->create();
        $user->assignRole('contador');

        $this->assertTrue($user->can('auditoria.accesos.ver'));
        $this->assertFalse($user->can('usuarios.crear'));
        $this->assertFalse($user->can('auditoria.sesiones.revocar'));
    }

    public function test_the_read_only_role_has_no_permissions_at_all()
    {
        $user = User::factory()->create();
        $user->assignRole('consulta');

        $this->assertFalse($user->can('usuarios.ver'));
        $this->assertFalse($user->can('auditoria.accesos.ver'));
    }

    /**
     * El seeder converge en el catalogo y no en la asignacion.
     *
     * Antes hacia `syncPermissions` incondicional, y este test verificaba
     * eso. Dejo de valer cuando los permisos de cada rol pasaron a
     * editarse desde Configuracion: con el comportamiento viejo, el
     * siguiente `db:seed` borraba en silencio lo que el area habia
     * configurado. Lo que el seeder sigue garantizando es el catalogo
     * —que exista todo permiso que una ruta exige, y ninguno mas— y la
     * linea de base de un rol que todavia no tiene nada.
     *
     * Ver Tests\Feature\Configuracion\RolesTest para la contracara: que
     * una edicion hecha desde la pantalla sobreviva al reseeding, y que
     * `roles:restaurar-predeterminados` la revierta a proposito.
     */
    public function test_the_seeder_is_idempotent()
    {
        $administrativo = Role::findByName('administrativo', 'web');
        $permisosPrevios = $administrativo->permissions()->count();

        Permission::findOrCreate('un.permiso.retirado', 'web');
        $administrativo->givePermissionTo('un.permiso.retirado');

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(5, Role::query()->count());

        // El permiso que ya no esta en el mapa se borra del catalogo, y con
        // el desaparece de todos los roles que lo tenian.
        $this->assertDatabaseMissing('permissions', ['name' => 'un.permiso.retirado']);
        $this->assertSame(
            $permisosPrevios,
            Role::findByName('administrativo', 'web')->permissions()->count(),
        );
    }
}
