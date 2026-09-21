<?php

declare(strict_types=1);

use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Modules\Ledger\Exceptions\ClosedPeriodException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SecurityHeaders::class,
            /*
             * Va al final del grupo `web` y no en el de `auth`: la marca
             * hay que mirarla en toda pantalla con sesion, y el propio
             * middleware se ocupa de dejar pasar al invitado.
             */
            ForcePasswordChange::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * Un período cerrado no es una pantalla de error.
         *
         * Que la caja esté cerrada para una fecha es una situación
         * **prevista y con salida** —se reabre el período—, así que se
         * devuelve a la pantalla donde estaba el operador con lo necesario
         * para explicárselo y llevarlo hasta ahí. Antes esto salía como
         * `QueryException` del trigger: la traza de PostgreSQL en la cara
         * de quien solo quería emitir un recibo.
         */
        $exceptions->render(function (ClosedPeriodException $e, Request $request) {
            if ($request->expectsJson()) {
                return null;
            }

            return back()->with('closedPeriod', $e->toArray());
        });

        /*
         * Un rechazo mientras se navega no expulsa del sistema.
         *
         * Inertia, ante una respuesta que no es suya, abre un modal con el
         * HTML crudo adentro: la pantalla de error tapando la aplicación,
         * sin barra lateral y sin forma de seguir salvo recargar. Para los
         * códigos que **no** significan que algo se rompió se devuelve una
         * pantalla de Inertia, y el operador queda donde estaba, con la
         * navegación puesta y el camino de migas de la pantalla que pidió.
         *
         * Quedan afuera a propósito:
         *
         * - 419 y 401, porque ahí no hay sesión que sostener. Van a la vista
         *   Blade, que lleva al ingreso y no depende de nada del sistema.
         * - 500 y 503, porque si la aplicación está rota, componer una
         *   respuesta de Inertia —que corre `share()` entero— es apostar a
         *   que lo que falló no estaba justo ahí. La vista Blade se dibuja
         *   sola, sin build y sin props compartidas.
         */
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            $codigo = $e->getStatusCode();

            if (! $request->header('X-Inertia') || ! in_array($codigo, [403, 404, 429], true)) {
                return null;
            }

            return Inertia::render('errors/error', [
                'status' => $codigo,
                'titulo' => __("errores.{$codigo}.titulo"),
                // El motivo que escribió quien puso la guarda explica mejor
                // que el texto genérico; si no vino, se usa el genérico.
                'detalle' => $e->getMessage() ?: __("errores.{$codigo}.detalle"),
                'volver' => __("errores.{$codigo}.volver"),
                'ayuda' => __('errores.ayuda', ['codigo' => (string) $codigo]),
            ])
                ->toResponse($request)
                ->setStatusCode($codigo);
        });
    })->create();
