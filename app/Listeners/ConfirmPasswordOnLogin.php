<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

/**
 * Quien acaba de entrar con su contraseña ya la confirmó.
 *
 * `RequirePassword` protege la sección de seguridad de un caso concreto: la
 * sesión que quedó abierta y alguien más se sienta en esa máquina. Volver a
 * pedirle la contraseña a quien la tipeó hace tres segundos no cubre ese
 * caso —cubre el mismo acto dos veces— y es exactamente lo que le pasaba al
 * usuario nuevo, obligado a cambiar su clave provisoria y frenado en el
 * camino por una pantalla que además se parecía al login.
 *
 * La ventana sigue siendo la de `auth.password_timeout`: pasadas esas horas,
 * la sesión abierta vuelve a pedir la contraseña, que es cuando la pregunta
 * tiene sentido.
 *
 * El método se llama `onLogin` y no `handle`: Laravel autodescubre todo
 * `handle*` en `app/Listeners` y el evento quedaría registrado dos veces.
 */
final class ConfirmPasswordOnLogin
{
    /**
     * Las dos rutas por las que se entra probando la contraseña.
     *
     * `two-factor.login.store` es el segundo paso de un ingreso que empezó
     * con la contraseña: ese request trae el código, pero la clave ya se
     * verificó en el paso anterior de la misma sesión.
     *
     * `passkey.login` **no está** y no es un olvido: quien entra con una
     * passkey nunca tipeó su contraseña, así que tocar la seguridad de la
     * cuenta tiene que seguir exigiéndosela.
     *
     * @var list<string>
     */
    private const PASSWORD_ROUTES = [
        'login.store',
        'two-factor.login.store',
    ];

    public function onLogin(Login $event, ?Request $request = null): void
    {
        // El request se resuelve al recibir el evento y no por constructor:
        // el listener se instancia una vez y el request es de este momento.
        $request ??= request();

        if (! $request->hasSession() || ! $request->routeIs(...self::PASSWORD_ROUTES)) {
            return;
        }

        $request->session()->put('auth.password_confirmed_at', time());
    }
}
