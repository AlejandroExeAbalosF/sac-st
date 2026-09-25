<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_screen_carries_the_security_headers()
    {
        $response = $this->get(route('login'));

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=(), payment=(), usb=()');
    }

    public function test_the_content_security_policy_is_restrictive()
    {
        $csp = $this->get(route('login'))->headers->get('Content-Security-Policy');

        $this->assertIsString($csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    /**
     * Regresión: los nombres de la lista de rutas enmarcables **tienen que
     * existir**.
     *
     * Ya pasó dos veces y las dos fallaron en silencio. La primera, la
     * lista traía un `receipts.preview` que nunca fue el nombre real. La
     * segunda, la etapa de Órdenes agregó tres visores en iframe y nadie
     * los sumó acá: el navegador mostraba «no se puede abrir esta página»
     * dentro del diálogo, sin ningún error del lado del servidor.
     *
     * Un nombre que no resuelve nunca activa `SAMEORIGIN`, así que la ruta
     * queda con `DENY` y el iframe muere. Esto lo atrapa al escribirlo.
     */
    public function test_every_framed_route_name_exists()
    {
        $rutas = config('security.headers.same_origin_frame_routes');

        $this->assertIsArray($rutas);
        $this->assertNotEmpty($rutas, 'La lista vacía deja todos los visores en iframe rotos.');

        foreach ($rutas as $nombre) {
            $this->assertNotNull(
                app('router')->getRoutes()->getByName($nombre),
                "La ruta «{$nombre}» de `same_origin_frame_routes` no existe: "
                .'el iframe que la muestre va a quedar en blanco.',
            );
        }
    }

    /**
     * Y cada una responde de verdad con la cabecera que la deja enmarcar.
     *
     * Que el nombre exista no alcanzaba: lo que rompe el visor es la
     * cabecera, y entre el nombre y la cabecera hay un middleware que
     * puede dejar de mirar la lista sin que nadie se entere.
     *
     * Se ejercita el middleware directo y no una petición completa. Estas
     * rutas exigen sesión, permiso y una cuota con su Orden y su egreso
     * cargados; y sin todo eso la respuesta la arma el manejador de
     * excepciones, **por fuera del middleware**, así que sale sin ninguna
     * cabecera y el test mediría cualquier otra cosa. Lo que decide es el
     * nombre de la ruta, y eso es lo que se prueba.
     */
    public function test_every_framed_route_answers_with_a_same_origin_frame_header()
    {
        /** @var list<string> $rutas */
        $rutas = config('security.headers.same_origin_frame_routes');

        foreach ($rutas as $nombre) {
            $response = $this->cabecerasDe($nombre);

            $this->assertSame(
                'SAMEORIGIN',
                $response->headers->get('X-Frame-Options'),
                "La ruta «{$nombre}» sale con `X-Frame-Options: DENY`.",
            );

            $this->assertStringContainsString(
                "frame-ancestors 'self'",
                (string) $response->headers->get('Content-Security-Policy'),
                "La ruta «{$nombre}» sale con `frame-ancestors` restrictivo: "
                .'el navegador va a mostrar «no se puede abrir esta página» '
                .'adentro del diálogo, sin ningún error del lado del servidor.',
            );
        }
    }

    /**
     * Regresión, y es la tercera vez que pasa lo mismo.
     *
     * El diálogo de entregar el egreso mostraba su vista previa en blanco
     * porque esta ruta nunca se sumó a la lista. Las dos veces anteriores
     * fueron un nombre mal escrito y tres visores de Órdenes sin declarar:
     * el test que había atrapaba lo primero y no lo segundo, porque
     * comprobaba que los nombres de la lista existieran y no que estuviera
     * la ruta que hacía falta.
     *
     * Por eso esta va nombrada y aparte: una lista que se verifica a sí
     * misma no puede avisar de lo que le falta.
     */
    public function test_the_expense_receipt_preview_can_be_framed()
    {
        $this->assertSame(
            'SAMEORIGIN',
            $this->cabecerasDe('haberes.installments.disbursement.preview')
                ->headers->get('X-Frame-Options'),
        );
    }

    /**
     * Sin `'unsafe-inline'` en los estilos: es de lo primero que marca un
     * escaneo de OWASP ZAP, y el data center escanea antes de publicar.
     *
     * Los `<style>` propios pasan por el nonce de la respuesta, que tiene
     * que ser el mismo en la cabecera y en el HTML: si no coinciden, la
     * pantalla sale sin estilos y ningún test de PHP se entera.
     */
    public function test_the_styles_go_by_nonce_and_not_by_unsafe_inline()
    {
        $response = $this->get(route('login'))->assertOk();
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression("/style-src 'self' 'nonce-([^']+)'/", $csp);
        $this->assertStringNotContainsString("'unsafe-inline'", $csp);
        $this->assertStringNotContainsString('style-src-attr', $csp);

        preg_match("/'nonce-([^']+)'/", $csp, $nonce);
        preg_match_all('/<style nonce="([^"]*)"/', (string) $response->getContent(), $estilos);

        $this->assertNotEmpty($estilos[1], 'La página no tiene ningún <style> con nonce.');

        foreach ($estilos[1] as $delHtml) {
            $this->assertSame($nonce[1], $delHtml);
        }

        // Y el que publica para lo que inyecta estilos desde JavaScript.
        $this->assertStringContainsString(
            '<meta property="csp-nonce" nonce="'.$nonce[1].'">',
            (string) $response->getContent(),
        );
    }

    /** Cada respuesta tiene su nonce: repetido, dejaría de servir. */
    public function test_each_response_gets_its_own_nonce()
    {
        $primera = (string) $this->get(route('login'))->headers->get('Content-Security-Policy');
        $segunda = (string) $this->get(route('login'))->headers->get('Content-Security-Policy');

        preg_match("/'nonce-([^']+)'/", $primera, $a);
        preg_match("/'nonce-([^']+)'/", $segunda, $b);

        $this->assertNotSame($a[1], $b[1]);
    }

    /**
     * Las vistas previas reproducen formularios preimpresos con atributos
     * `style="..."`: solo ahí se permiten atributos, y solo atributos.
     */
    public function test_only_document_previews_allow_style_attributes()
    {
        /** @var list<string> $rutas */
        $rutas = config('security.headers.same_origin_frame_routes');

        foreach ($rutas as $nombre) {
            $csp = (string) $this->cabecerasDe($nombre)->headers->get('Content-Security-Policy');

            $this->assertStringContainsString("style-src-attr 'unsafe-inline'", $csp, "La ruta «{$nombre}».");
            $this->assertDoesNotMatchRegularExpression("/style-src [^;]*'unsafe-inline'/", $csp);
        }
    }

    /**
     * Un 404 sin ruta también es HTML y también sale con su política.
     *
     * Colgado del grupo `web`, el middleware no corría para una dirección
     * que no existe, y esas respuestas salían sin CSP.
     */
    public function test_a_page_without_a_route_carries_the_policy_too()
    {
        $response = $this->get('/una-direccion-que-no-existe')->assertNotFound();

        $this->assertStringContainsString(
            "default-src 'self'",
            (string) $response->headers->get('Content-Security-Policy'),
        );
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    /** Y una pantalla común sigue sin poder enmarcarse. */
    public function test_an_ordinary_screen_can_not_be_framed()
    {
        $this->get(route('login'))->assertHeader('X-Frame-Options', 'DENY');
    }

    /**
     * El front recibe qué puede enmarcar, para poder avisar cuando falta.
     *
     * Es la otra mitad de la guarda: acá se comprueba que los nombres
     * declarados producen cabeceras; la prop es lo que permite que
     * `DocumentFrame` reviente en desarrollo cuando alguien enmarca una
     * dirección que **no** está declarada, que es el caso que ningún test
     * de PHP puede ver porque vive en un `<iframe>` del front.
     *
     * Viajan las plantillas y no los nombres: lo que el componente tiene
     * en la mano es una URL, y cotejar contra lo que el navegador va a
     * pedir es lo que cierra el circuito.
     */
    public function test_the_front_receives_which_documents_it_may_frame()
    {
        $this->actingAs($this->operador())
            ->get(route('inicio'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                /*
                 * `collect()` y no `is_array()`: la aserción fluida le
                 * pasa al cierre una colección, no el arreglo crudo.
                 */
                ->where('framableDocuments', fn (mixed $plantillas): bool => collect($plantillas)
                    ->contains('haberes/cuotas/{installment}/egreso/previsualizar')
                    && collect($plantillas)->count() === count(config('security.headers.same_origin_frame_routes')),
                ),
            );
    }

    /**
     * Las cabeceras que el middleware le pone a una ruta, por su nombre.
     *
     * Arma la petición con la ruta ya resuelta, que es el estado en el que
     * el middleware la recibe en producción: sus cabeceras se escriben
     * después de `$next`, cuando el router ya decidió qué ruta era.
     */
    private function cabecerasDe(string $nombre): Response
    {
        $ruta = app('router')->getRoutes()->getByName($nombre);

        $this->assertNotNull($ruta, "La ruta «{$nombre}» no existe.");

        $request = Request::create(
            '/'.ltrim((string) preg_replace('/\{[^}]+\}/', '1', $ruta->uri()), '/'),
        );
        $request->setRouteResolver(fn () => $ruta);

        return (new SecurityHeaders)->handle($request, fn (): Response => new Response('el documento'));
    }

    /**
     * Regresión: `upgrade-insecure-requests` reescribe a https todo pedido
     * http de la página, **incluidas las redirecciones**. Sobre un servidor
     * de desarrollo sin TLS eso rompe la navegación: al guardar un
     * formulario, el navegador intenta https, que no existe, y la conexión
     * se cierra sin ningún mensaje visible.
     */
    public function test_it_does_not_upgrade_requests_over_plain_http()
    {
        $csp = (string) $this->get(route('login'))->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString('upgrade-insecure-requests', $csp);
    }

    public function test_it_does_upgrade_requests_over_https()
    {
        $csp = (string) $this->get('https://localhost/login')
            ->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('upgrade-insecure-requests', $csp);
    }

    /**
     * HSTS solo tiene sentido sobre HTTPS: anunciarlo por HTTP no protege
     * y confunde a quien audita las cabeceras.
     */
    public function test_hsts_is_not_announced_over_plain_http()
    {
        $this->get(route('login'))->assertHeaderMissing('Strict-Transport-Security');
    }

    /**
     * Regresión: con Vite corriendo, la CSP tiene que dejar pasar su
     * servidor de desarrollo. Cuando no lo hacía, la pantalla quedaba en
     * blanco y el único rastro estaba en la consola del navegador.
     */
    public function test_the_vite_dev_server_is_allowed_while_it_is_running()
    {
        $this->givenViteIsRunningAt('http://localhost:5173');

        $csp = $this->get(route('login'))->headers->get('Content-Security-Policy');

        $this->assertIsString($csp);

        foreach (['script-src', 'style-src', 'font-src', 'img-src', 'connect-src'] as $directive) {
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($directive, '/').'[^;]*http:\/\/localhost:5173/',
                $csp,
                "La directiva {$directive} debería admitir el servidor de Vite.",
            );
        }

        $this->assertStringContainsString('ws://localhost:5173', $csp);

        // Con un nonce en la directiva, el navegador ignoraría el
        // `'unsafe-inline'` que necesitan el HMR y React Refresh.
        $this->assertMatchesRegularExpression("/style-src [^;]*'unsafe-inline'/", $csp);
        $this->assertDoesNotMatchRegularExpression("/style-src [^;]*'nonce-/", $csp);
    }

    /**
     * Un literal IPv6 entre corchetes no es una fuente válida de CSP: el
     * navegador la descarta y bloquea todo el servidor de desarrollo. No
     * se puede corregir desde el middleware —el HTML ya apunta ahí—, pero
     * sí dejar dicho qué pasa, para que la próxima vez el diagnóstico esté
     * en el log y no haya que deducirlo de una pantalla en blanco.
     */
    public function test_it_warns_when_vite_publishes_on_an_ipv6_literal()
    {
        Log::shouldReceive('channel')->with('security')->andReturnSelf();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'vite.config.ts')
                && $context['origin'] === 'http://[::1]:5173');

        $this->givenViteIsRunningAt('http://[::1]:5173');

        $this->get(route('login'))->assertOk();
    }

    public function test_the_headers_can_be_turned_off_by_configuration()
    {
        config(['security.enabled' => false]);

        $this->get(route('login'))->assertHeaderMissing('Content-Security-Policy');
    }

    /**
     * Simula que Vite está corriendo escribiendo `public/hot`, que es de
     * donde el middleware lee el origen real.
     */
    private function givenViteIsRunningAt(string $origin): void
    {
        $hotFile = public_path('hot');

        // El archivo puede existir de verdad porque el desarrollador tiene
        // Vite levantado mientras corre los tests. Se preserva su contenido
        // y se restaura al terminar: un test no puede tumbarle el entorno.
        $original = file_exists($hotFile) ? file_get_contents($hotFile) : null;

        file_put_contents($hotFile, $origin);

        $this->beforeApplicationDestroyed(function () use ($hotFile, $original): void {
            if ($original === null || $original === false) {
                @unlink($hotFile);

                return;
            }

            file_put_contents($hotFile, $original);
        });
    }
}
