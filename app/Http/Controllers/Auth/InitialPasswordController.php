<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\InitialPasswordRequest;
use App\Models\User;
use App\Modules\Shared\Actions\ChangeOwnPassword;
use App\Modules\Shared\Data\PasswordPolicyData;
use App\Modules\Shared\Enums\LoginEventType;
use App\Modules\Shared\Models\UserLoginEvent;
use App\Support\Ui\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La pantalla donde el usuario deja la contraseña temporal y elige la suya.
 *
 * Es la única a la que llega mientras tenga puesta `must_change_password`
 * (ver ForcePasswordChange), y por eso no se dibuja adentro del sistema:
 * sin barra lateral ni secciones de la cuenta, que con la marca puesta eran
 * enlaces que lo devolvían siempre al mismo lugar. Es la continuación del
 * login, y se ve como tal.
 */
class InitialPasswordController extends Controller
{
    public function edit(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Sin la marca no hay nada que hacer acá, y la pantalla no puede
        // quedar como un atajo para cambiar la clave sin la actual.
        if (! $user->must_change_password) {
            return to_route('inicio');
        }

        return Inertia::render('auth/primer-ingreso', [
            'firstName' => $user->first_name,
            /*
             * La misma marca la ponen el alta y el restablecimiento desde
             * Usuarios. Quien ya eligió una clave alguna vez no está
             * entrando por primera vez: se la restablecieron, y la pantalla
             * se lo dice así.
             */
            'wasReset' => UserLoginEvent::query()
                ->where('user_id', $user->id)
                ->where('event_type', LoginEventType::PasswordChanged->value)
                ->exists(),
            'requiresCurrentPassword' => InitialPasswordRequest::needsCurrentPassword($request),
            'passwordPolicy' => PasswordPolicyData::fromDefaults(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function update(InitialPasswordRequest $request, ChangeOwnPassword $changeOwnPassword): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->must_change_password) {
            return to_route('inicio');
        }

        $changeOwnPassword->handle(
            $user,
            (string) $request->validated('password'),
            $request->session()->getId(),
        );

        Toast::success(__('Listo, ya tenés tu contraseña.'));

        return to_route('inicio');
    }
}
