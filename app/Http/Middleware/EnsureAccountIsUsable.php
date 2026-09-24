<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Shared\Actions\RecordLoginEvent;
use App\Modules\Shared\Enums\LoginEventType;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Una sesión vale mientras la persona siga habilitada, y solo si nació de
 * un ingreso explícito.
 *
 * El control de `is_active` estaba únicamente en el login con contraseña.
 * Todo lo que abre sesión por otro camino lo salteaba:
 *
 * - la cookie de «recordarme», que duraba 400 días y sobrevivía a la baja
 *   y al restablecimiento de la clave;
 * - la passkey, que entra sin pasar por `authenticateUsing`.
 *
 * Mirarlo acá, en cada pedido, cubre esas vías y las que aparezcan. Y
 * rechazar el ingreso por cookie de recordatorio es lo que hace efectiva
 * la decisión de no ofrecer «recordarme»: en un sistema que mueve dinero de
 * terceros, la sesión dura lo que dura la actividad, y una cookie que
 * alguien haya conservado no la resucita.
 */
final class EnsureAccountIsUsable
{
    public function __construct(private readonly RecordLoginEvent $registrarAcceso) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $inactivo = ! $user->isActive();

        if (! $inactivo && ! Auth::guard()->viaRemember()) {
            return $next($request);
        }

        $this->registrarAcceso->handle(
            type: LoginEventType::SessionRevoked,
            user: $user,
            meta: ['reason' => $inactivo ? 'usuario inactivo' : 'sesión recordada sin ingreso explícito'],
            request: $request,
        );

        Auth::guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $respuesta = redirect()->route('login');

        return $inactivo
            ? $respuesta->withErrors([
                Fortify::username() => 'Tu usuario está inactivo. Comunicate con el administrador del sistema.',
            ])
            : $respuesta;
    }
}
