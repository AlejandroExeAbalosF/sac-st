<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    /**
     * El sistema se muestra siempre en modo claro.
     *
     * La cookie `appearance` se ignora a propósito: el modo oscuro existe
     * en los tokens pero todavía no recibió su pasada de diseño, y un
     * usuario que lo eligió antes —o que tiene el sistema operativo en
     * oscuro— no debería seguir viendo una pantalla a medio terminar.
     *
     * Para reactivarlo, ver DARK_MODE_ENABLED en
     * resources/js/hooks/use-appearance.tsx.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        View::share('appearance', 'light');

        return $next($request);
    }
}
