<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Modules\Shared\Actions\RecordLoginEvent;
use App\Modules\Shared\Actions\RevokeUserSessions;
use App\Modules\Shared\Enums\LoginEventType;
use App\Support\Ui\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'canManagePasskeys' => Features::canManagePasskeys(),
            'passkeys' => Features::canManagePasskeys()
                ? $request->user()
                    ->passkeys()
                    ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
                    ->latest()
                    ->get()
                    ->map(fn ($passkey) => [
                        'id' => $passkey->id,
                        'name' => $passkey->name,
                        'authenticator' => $passkey->authenticator,
                        'created_at_diff' => $passkey->created_at->diffForHumans(),
                        'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
                    ])
                    ->values()
                    ->all()
                : [],
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            /*
             * Con la marca puesta, la pantalla explica por qué el sistema
             * lo trajo hasta acá y esconde todo lo demás: no tiene sentido
             * ofrecerle registrar una passkey a quien todavía usa la clave
             * que le dictó otra persona.
             */
            'mustChangePassword' => $request->user()->must_change_password,
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $request->user()->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('mi-cuenta/seguridad', $props);
    }

    /**
     * Update the user's password.
     */
    public function update(
        PasswordUpdateRequest $request,
        RecordLoginEvent $recordLoginEvent,
        RevokeUserSessions $revokeUserSessions,
    ): RedirectResponse {
        $user = $request->user();
        $eraObligatorio = $user->must_change_password;

        $user->update([
            'password' => $request->password,
            // Acá termina el período en que dos personas conocen la clave.
            'must_change_password' => false,
        ]);

        /*
         * El cambio de contraseña queda en el historial de accesos. El
         * `CHECK` de la tabla ya contemplaba este tipo de evento desde el
         * principio; lo que faltaba era alguien que lo escribiera.
         */
        $recordLoginEvent->handle(
            type: LoginEventType::PasswordChanged,
            user: $user,
            meta: $eraObligatorio ? ['reason' => 'cambio obligatorio'] : [],
        );

        /*
         * Cambiar la clave es lo primero que hace quien sospecha que otro
         * la conoce. Si las otras sesiones siguieran abiertas, el cambio no
         * lo sacaría a ese otro. La de esta pantalla se conserva.
         */
        $revokeUserSessions->handle(
            $user,
            exceptSessionId: $request->session()->getId(),
            reason: 'contraseña cambiada',
        );

        Toast::success(__('Contraseña actualizada.'));

        // Venía obligado: recién ahora tiene sentido llevarlo al sistema.
        return $eraObligatorio ? to_route('inicio') : back();
    }
}
