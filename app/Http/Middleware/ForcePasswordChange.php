<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mientras la contraseña siga siendo la que asignó el administrador, el
 * usuario no llega a ninguna otra pantalla.
 *
 * Es lo que le pone plazo al único momento en que dos personas conocen la
 * misma clave. Sin esto, la contraseña temporal se vuelve permanente el
 * mismo día que se entrega, y la firma de un recibo deja de identificar a
 * una sola persona.
 *
 * Lo lleva al primer ingreso, que es una pantalla aparte y no «Mi cuenta ›
 * Seguridad»: esa se dibuja con la barra lateral y las secciones de la
 * cuenta, y con la marca puesta cada uno de esos enlaces lo devolvía al
 * mismo lugar, como si el sistema no respondiera.
 *
 * Quedan afuera la pantalla, su guardado y la salida: encerrar a alguien sin
 * poder cerrar sesión sería un callejón. La confirmación de contraseña ya no
 * hace falta —el primer ingreso la pide en su propio formulario cuando
 * corresponde—, así que tampoco pasa.
 */
final class ForcePasswordChange
{
    /**
     * Rutas que se siguen atendiendo con la marca puesta.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        'primer-ingreso',
        'primer-ingreso.update',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->must_change_password) {
            return $next($request);
        }

        if ($request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        return redirect()->route('primer-ingreso');
    }
}
