<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad HTTP.
 *
 * El sistema queda detrás de un proxy inverso del Ministerio y sin
 * exposición directa a internet, pero eso protege del exterior, no del
 * navegador: clickjacking, sniffing de tipo y scripts inyectados siguen
 * siendo problemas locales.
 *
 * Es middleware **global**, no del grupo `web`: un 404 sin ruta y las
 * pantallas de error también son HTML y también tienen que salir con su
 * política. Colgado de `web`, esas respuestas salían sin CSP, que es de lo
 * primero que marca un escaneo de OWASP ZAP.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /*
         * El nonce se genera antes de armar la respuesta: las vistas lo
         * leen con `Vite::cspNonce()` para sus `<style>`, y `@vite` y
         * `@fonts` lo agregan solos a lo que imprimen.
         */
        if (config('security.enabled', true) && config('security.csp.enabled', true)) {
            Vite::useCspNonce();
        }

        /** @var Response $response */
        $response = $next($request);

        if (! config('security.enabled', true)) {
            return $response;
        }

        $sameOriginFrame = $this->allowsSameOriginFrame($request);

        $response->headers->set(
            'X-Frame-Options',
            $sameOriginFrame ? 'SAMEORIGIN' : (string) config('security.headers.x_frame_options', 'DENY'),
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', (string) config('security.headers.referrer_policy'));
        $response->headers->set('Permissions-Policy', (string) config('security.headers.permissions_policy'));
        $response->headers->set('Cross-Origin-Opener-Policy', (string) config('security.headers.cross_origin_opener_policy'));
        $response->headers->set('Cross-Origin-Resource-Policy', (string) config('security.headers.cross_origin_resource_policy'));

        $csp = $this->contentSecurityPolicy($sameOriginFrame, $request->isSecure());

        if ($csp !== '') {
            $response->headers->set(
                config('security.csp.report_only', false)
                    ? 'Content-Security-Policy-Report-Only'
                    : 'Content-Security-Policy',
                $csp,
            );
        }

        if (config('security.hsts.enabled', true) && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', $this->hsts());
        }

        return $response;
    }

    private function allowsSameOriginFrame(Request $request): bool
    {
        $routes = config('security.headers.same_origin_frame_routes', []);

        return is_array($routes) && $routes !== [] && $request->routeIs(...$routes);
    }

    private function contentSecurityPolicy(bool $sameOriginFrame, bool $seguro): string
    {
        if (! config('security.csp.enabled', true)) {
            return '';
        }

        /** @var array<string, list<string>|bool> $directives */
        $directives = config('security.csp.directives', []);

        /*
         * `upgrade-insecure-requests` solo sobre HTTPS.
         *
         * Le dice al navegador que reescriba a https todo pedido http de la
         * página, incluidas las redirecciones. Sobre un servidor de
         * desarrollo sin TLS eso rompe la navegación: al guardar un
         * formulario, la respuesta redirige a http y el navegador intenta
         * https, que no existe, y la conexión se cierra. Costó un rato
         * entender por qué «no pasaba nada» al guardar.
         */
        if (! $seguro) {
            unset($directives['upgrade-insecure-requests']);
        }

        $directives = $this->withStyleNonce($directives);
        $directives = $this->withViteDevServer($directives);

        if ($sameOriginFrame) {
            $directives['frame-ancestors'] = ["'self'"];

            /*
             * Las vistas previas de documentos reproducen formularios
             * preimpresos al milímetro con atributos `style="..."`: unos
             * ciento cuarenta, compartidos con el PDF. Acá se permiten
             * **atributos**, no bloques `<style>`, y solo en estas rutas: el
             * HTML se escapa entero y la página no tiene formularios, así
             * que no hay por dónde colar uno.
             */
            $directives['style-src-attr'] = ["'unsafe-inline'"];
        }

        $parts = [];

        foreach ($directives as $directive => $sources) {
            if ($sources === true) {
                $parts[] = $directive;

                continue;
            }

            if (! is_array($sources) || $sources === []) {
                continue;
            }

            $parts[] = $directive.' '.implode(' ', $sources);
        }

        return implode('; ', $parts);
    }

    /**
     * Agrega a `style-src` el nonce de esta respuesta y los hashes de los
     * `<style>` que inyectan librerías sin soporte de nonce.
     *
     * @param  array<string, list<string>|bool>  $directives
     * @return array<string, list<string>|bool>
     */
    private function withStyleNonce(array $directives): array
    {
        $sources = $directives['style-src'] ?? [];

        if (! is_array($sources)) {
            return $directives;
        }

        $nonce = Vite::cspNonce();

        if ($nonce !== null) {
            $sources[] = "'nonce-{$nonce}'";
        }

        /** @var array<string, string> $hashes */
        $hashes = config('security.csp.style_hashes', []);

        $directives['style-src'] = array_values(array_unique([...$sources, ...array_values($hashes)]));

        return $directives;
    }

    /**
     * Habilita el servidor de desarrollo de Vite en la política.
     *
     * Mientras Vite corre, el navegador pide los módulos, los estilos y
     * las tipografías a su servidor, no al de Laravel. El origen se lee de
     * `public/hot` —que es donde Vite lo escribe— en lugar de mantenerlo
     * en una lista fija que se desactualiza sola.
     *
     * @param  array<string, list<string>|bool>  $directives
     * @return array<string, list<string>|bool>
     */
    private function withViteDevServer(array $directives): array
    {
        /*
         * Se lee de una, sin preguntar antes si existe.
         *
         * Vite borra `public/hot` al apagarse, y entre el `file_exists` y
         * la lectura hay lugar para que desaparezca: la carrera existe y
         * se vio, tirando un 500 en una request que no tenia nada que ver.
         * Preguntar y despues leer no arregla nada; leer y aceptar que
         * falle, si.
         */
        $contenido = @file_get_contents(public_path('hot'));

        if ($contenido === false) {
            return $directives;
        }

        $origin = trim($contenido);

        if ($origin === '') {
            return $directives;
        }

        // La gramática de CSP no admite literales IPv6 entre corchetes: el
        // navegador descarta la fuente y bloquea todo el servidor de Vite,
        // dejando la pantalla en blanco. No se puede reescribir el origen
        // —el HTML ya apunta ahí—, pero sí decir en voz alta qué pasa y
        // cómo se arregla, que es lo que falta cuando esto ocurre.
        if (str_contains($origin, '[')) {
            Log::channel('security')->warning(
                'Vite está publicando en un literal IPv6 y la CSP no puede expresarlo. '
                .'Agregá `server: { host: "localhost" }` en vite.config.ts y reiniciá Vite.',
                ['origin' => $origin],
            );
        }

        // El HMR viaja por websocket sobre el mismo origen.
        $socket = str_starts_with($origin, 'https://')
            ? 'wss://'.substr($origin, 8)
            : 'ws://'.substr($origin, 7);

        /** @var list<string> $targets */
        $targets = config('security.csp.vite_dev_directives', []);

        foreach ($targets as $directive) {
            $sources = $directives[$directive] ?? [];

            if (! is_array($sources)) {
                continue;
            }

            $sources[] = $origin;

            if ($directive === 'connect-src') {
                $sources[] = $socket;
            }

            // React Refresh inyecta su preámbulo como script en línea y
            // Vite escribe estilos en línea al aplicar HMR. Con un nonce o
            // un hash en la misma directiva, el navegador ignora
            // `'unsafe-inline'`: en desarrollo se sacan.
            if (in_array($directive, ['script-src', 'style-src'], true)) {
                $sources = array_values(array_filter(
                    $sources,
                    fn (string $source): bool => ! str_starts_with($source, "'nonce-")
                        && ! str_starts_with($source, "'sha256-"),
                ));
                $sources[] = "'unsafe-inline'";
            }

            $directives[$directive] = array_values(array_unique($sources));
        }

        return $directives;
    }

    private function hsts(): string
    {
        return sprintf(
            'max-age=%d%s%s',
            (int) config('security.hsts.max_age', 31_536_000),
            config('security.hsts.include_subdomains', true) ? '; includeSubDomains' : '',
            config('security.hsts.preload', false) ? '; preload' : '',
        );
    }
}
