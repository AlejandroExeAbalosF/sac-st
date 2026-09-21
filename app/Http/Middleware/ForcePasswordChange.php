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
 * Quedan afuera las rutas que hacen falta para poder cambiarla —la propia
 * pantalla de seguridad, la confirmación de contraseña que la protege— y
 * la salida: encerrar a alguien sin poder cerrar sesión sería un callejón.
 */
final class ForcePasswordChange
{
    /**
     * Rutas que se siguen atendiendo con la marca puesta.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        'mi-cuenta.seguridad',
        'user-password.update',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
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

        return redirect()->route('mi-cuenta.seguridad');
    }
}
