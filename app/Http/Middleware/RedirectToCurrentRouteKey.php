<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Routing\ReadableRouteKey;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Acomoda la dirección de una pantalla a la actual.
 *
 * Un registro con `ReadableRouteKey` resuelve por su id aunque falte el
 * número o haya quedado viejo —un enlace armado solo con el id, o uno
 * guardado antes de corregir el número—. Acá se redirige a la dirección con
 * el número de hoy, así la barra siempre muestra lo que corresponde.
 *
 * Solo en las pantallas (`GET`): un envío ya llegó a donde tenía que llegar
 * por el id, y redirigirlo perdería lo enviado.
 */
final class RedirectToCurrentRouteKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $ruta = $request->route();

        if (! $request->isMethod('GET') || $ruta === null || $ruta->getName() === null) {
            return $next($request);
        }

        foreach ($ruta->parameters() as $nombre => $valor) {
            if (! $valor instanceof Model || ! in_array(ReadableRouteKey::class, class_uses_recursive($valor), true)) {
                continue;
            }

            if ($ruta->originalParameter($nombre) !== $valor->getRouteKey()) {
                return redirect()->route($ruta->getName(), [...$ruta->parameters(), ...$request->query()]);
            }
        }

        return $next($request);
    }
}
