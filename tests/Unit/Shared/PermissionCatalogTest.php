<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Modules\Shared\Enums\SystemRole;
use App\Modules\Shared\Permissions\PermissionCatalog;
use App\Modules\Shared\Permissions\PermissionDefinition;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\TestCase;

/**
 * El catálogo de permisos y las rutas que los exigen no pueden desfasarse.
 *
 * Que todo permiso sembrado lo pregunte alguien lo cubre `PermisosTest`.
 * Acá va la otra dirección —toda ruta que exige un permiso lo encuentra
 * declarado y explicado— y las guardas con las que se arma el catálogo.
 */
class PermissionCatalogTest extends TestCase
{
    public function test_todo_permiso_que_exige_una_ruta_esta_en_el_catalogo(): void
    {
        $catalogo = app(PermissionCatalog::class);
        $exigidos = [];

        /** @var RouteDefinition $ruta */
        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            foreach ($ruta->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
                    $exigidos[] = explode(',', substr($middleware, 4))[0];
                }
            }
        }

        $this->assertNotEmpty($exigidos, 'No se encontró ninguna ruta con permiso: se rompió la prueba, no el catálogo.');

        $faltantes = array_values(array_unique(array_filter(
            $exigidos,
            fn (string $permiso): bool => ! $catalogo->has($permiso),
        )));

        $this->assertSame([], $faltantes, 'Estas rutas exigen permisos que el catálogo no declara: nadie salvo el administrador podría recibirlos.');
    }

    public function test_los_grupos_siguen_el_orden_del_circuito(): void
    {
        $grupos = array_column(app(PermissionCatalog::class)->grouped(), 'key');

        $this->assertSame('expedientes', $grupos[0]);
        $this->assertLessThan(array_search('egresos', $grupos, true), array_search('recepciones', $grupos, true));
        $this->assertNotContains('desarrollo', $grupos);
    }

    public function test_las_capacidades_de_desarrollo_solo_salen_si_se_piden(): void
    {
        $catalogo = app(PermissionCatalog::class);

        $this->assertContains('desarrollo', array_column($catalogo->grouped(includeDeveloperAbilities: true), 'key'));
        $this->assertSame(['dev.forzar-cbu'], $catalogo->defaultsFor(SystemRole::SuperAdmin));
    }

    public function test_registrar_dos_veces_el_mismo_permiso_falla(): void
    {
        $catalogo = $this->catalogoDePrueba();
        $catalogo->register($this->permiso('prueba.ver'));

        $this->expectException(LogicException::class);

        $catalogo->register($this->permiso('prueba.ver'));
    }

    public function test_un_grupo_sin_registrar_falla(): void
    {
        $this->expectException(LogicException::class);

        $this->catalogoDePrueba()->register($this->permiso('prueba.ver', group: 'inexistente'));
    }

    public function test_un_permiso_sin_descripcion_falla(): void
    {
        $this->expectException(LogicException::class);

        $this->catalogoDePrueba()->register($this->permiso('prueba.ver', description: '  '));
    }

    public function test_un_rol_que_no_existe_falla(): void
    {
        $this->expectException(LogicException::class);

        $this->catalogoDePrueba()->register($this->permiso('prueba.ver', roles: ['tesorero']));
    }

    public function test_una_capacidad_de_desarrollo_para_otro_rol_que_super_admin_falla(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('solo puede otorgarse a super-admin');

        $this->catalogoDePrueba()->register($this->permiso('dev.atajo', roles: ['super-admin', 'contador']));
    }

    public function test_un_permiso_no_delegable_no_va_de_fabrica_a_un_rol_de_la_matriz(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no se delega');

        $this->catalogoDePrueba()->register(
            new PermissionDefinition('prueba.reservado', 'Rótulo', 'Hace algo.', 'pruebas', ['administrador', 'contador'], delegable: false),
        );
    }

    public function test_no_se_delegan_la_matriz_ni_la_administracion_de_usuarios(): void
    {
        $noDelegables = app(PermissionCatalog::class)->nonDelegable();
        sort($noDelegables);

        $this->assertSame([
            'multas.resolver-recurso',
            'roles.gestionar',
            'usuarios.crear',
            'usuarios.desactivar',
            'usuarios.editar',
            'usuarios.restablecer-password',
        ], $noDelegables);
    }

    private function catalogoDePrueba(): PermissionCatalog
    {
        $catalogo = new PermissionCatalog;
        $catalogo->registerGroup('pruebas', 'Pruebas', 1);

        return $catalogo;
    }

    /**
     * @param  list<string>  $roles
     */
    private function permiso(
        string $name,
        string $group = 'pruebas',
        string $description = 'Hace algo.',
        array $roles = ['contador'],
    ): PermissionDefinition {
        return new PermissionDefinition($name, 'Rótulo', $description, $group, $roles);
    }
}
