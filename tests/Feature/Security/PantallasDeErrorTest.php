<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Un rechazo mientras se navega no expulsa del sistema.
 *
 * Inertia, ante una respuesta que no es suya, abre un modal con el HTML crudo
 * adentro: la pantalla de error tapando la aplicación, sin barra lateral y sin
 * forma de seguir salvo recargar. Para los códigos que no significan que algo
 * se rompió se devuelve una pantalla de Inertia, y el operador queda donde
 * estaba.
 *
 * Los otros —419 sin sesión, 500 con la aplicación rota— siguen yendo a la
 * vista Blade, que se dibuja sola. Eso también se verifica acá: la parte
 * difícil de esta decisión no es lo que se convierte, es lo que no.
 *
 * Las aserciones van sobre el JSON y no con `assertInertia`, que solo sabe
 * leer la carga HTML inicial: acá lo que se prueba es justamente la respuesta
 * a una navegación, que es XHR.
 */
class PantallasDeErrorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Los encabezados de una navegación de Inertia.
     *
     * La versión sale del middleware y no de `Inertia::getVersion()`: esa
     * última todavía no está puesta cuando el test arma el pedido, y una
     * versión que no coincide devuelve 409 antes de llegar a la excepción.
     *
     * @return array<string, string>
     */
    private function comoInertia(): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)
                ->version(request()),
        ];
    }

    public function test_un_404_navegando_devuelve_la_pantalla_adentro_del_sistema(): void
    {
        Route::middleware('web')->get('/prueba-404', fn () => abort(404));

        $this
            ->actingAs(User::factory()->create())
            ->withHeaders($this->comoInertia())
            ->get('/prueba-404')
            ->assertStatus(404)
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'errors/error')
            ->assertJsonPath('props.status', 404)
            ->assertJsonPath('props.titulo', 'No encontramos esa página')
            ->assertJsonStructure(['props' => ['detalle', 'volver', 'ayuda']]);
    }

    /**
     * El motivo que escribió quien puso la guarda explica mejor que el texto
     * genérico, y llega hasta la pantalla.
     */
    public function test_un_403_navegando_conserva_el_motivo(): void
    {
        Route::middleware('web')->get(
            '/prueba-403',
            fn () => abort(403, 'La caja ya fue cerrada por otro operador.'),
        );

        $this
            ->actingAs(User::factory()->create())
            ->withHeaders($this->comoInertia())
            ->get('/prueba-403')
            ->assertStatus(403)
            ->assertJsonPath('component', 'errors/error')
            ->assertJsonPath(
                'props.detalle',
                'La caja ya fue cerrada por otro operador.',
            );
    }

    /**
     * El límite conocido, escrito para que se vea.
     *
     * Una dirección que no coincide con ninguna ruta se rechaza **antes** del
     * grupo `web`: no corre la sesión ni `share()`, así que la pantalla llega
     * sin `auth` y el layout la muestra sola, sin barra lateral.
     *
     * Se deja así a propósito. La ruta de respaldo que lo arreglaría —un
     * `Route::fallback()` al final de web.php— hace que un POST a una
     * dirección inexistente pase de 404 a 405, y la variante que acepta todos
     * los verbos convierte en 404 los 405 legítimos. Las dos rompen tests que
     * documentan decisiones tomadas.
     *
     * El caso además casi no ocurre: los enlaces de adentro los genera
     * Wayfinder desde las rutas reales, así que una navegación de Inertia
     * llega siempre a una ruta que existe, y ahí el rechazo sí trae sesión.
     */
    public function test_una_direccion_sin_ruta_llega_sin_sesion_y_se_muestra_sola(): void
    {
        $this
            ->actingAs(User::factory()->create())
            ->withHeaders($this->comoInertia())
            ->get('/esta-direccion-no-existe')
            ->assertStatus(404)
            ->assertJsonPath('component', 'errors/error')
            ->assertJsonMissingPath('props.auth');
    }

    /**
     * Sin `X-Inertia` no hay aplicación cargada que conservar: manda la vista
     * Blade, que se dibuja sola y sin depender del build.
     */
    public function test_un_pedido_que_no_es_de_inertia_recibe_la_vista_suelta(): void
    {
        Route::middleware('web')->get('/prueba-404-directa', fn () => abort(404));

        $respuesta = $this->get('/prueba-404-directa');

        $respuesta->assertStatus(404);
        $respuesta->assertSee('No encontramos esa página', false);
        $this->assertStringContainsString(
            'lang="es"',
            (string) $respuesta->getContent(),
        );
        $this->assertNull($respuesta->headers->get('X-Inertia'));
    }

    /**
     * 419 queda deliberadamente afuera: la sesión ya no existe, así que no hay
     * sistema adentro del cual quedarse. La vista Blade lleva al ingreso.
     */
    public function test_una_sesion_vencida_no_se_convierte_en_pantalla_de_inertia(): void
    {
        Route::middleware('web')->get('/prueba-419', fn () => abort(419));

        $respuesta = $this
            ->withHeaders($this->comoInertia())
            ->get('/prueba-419');

        $respuesta->assertStatus(419);
        $respuesta->assertSee('La sesión venció', false);
        $this->assertNull($respuesta->headers->get('X-Inertia'));
    }

    /**
     * Con la aplicación rota, componer una respuesta de Inertia corre
     * `share()` entero: es apostar a que lo que falló no estaba justo ahí.
     */
    public function test_un_error_del_servidor_no_se_convierte_en_pantalla_de_inertia(): void
    {
        Route::middleware('web')->get('/prueba-500', fn () => abort(500));

        $respuesta = $this
            ->withHeaders($this->comoInertia())
            ->get('/prueba-500');

        $respuesta->assertStatus(500);
        $this->assertNull($respuesta->headers->get('X-Inertia'));
    }

    /**
     * Los textos salen de `lang/es/errores.php`, que es el mismo archivo que
     * leen las vistas Blade: la situación se explica una sola vez.
     */
    public function test_las_dos_pantallas_dicen_lo_mismo(): void
    {
        Route::middleware('web')->get('/prueba-doble', fn () => abort(404));

        $titulo = (string) __('errores.404.titulo');

        // El pedido directo va primero: `withHeaders` queda pegado al test, y
        // después de pedirla como Inertia ya no hay forma de pedirla suelta.
        $this->get('/prueba-doble')->assertSee($titulo, false);

        $this
            ->actingAs(User::factory()->create())
            ->withHeaders($this->comoInertia())
            ->get('/prueba-doble')
            ->assertJsonPath('props.titulo', $titulo);
    }
}
