<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Modules\Shared\Actions\ChangeOwnPassword;
use App\Modules\Shared\Data\PasswordPolicyData;
use App\Modules\Shared\Enums\LoginEventType;
use App\Modules\Shared\Models\UserLoginEvent;
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
            'passwordPolicy' => PasswordPolicyData::fromDefaults(),
            /*
             * Cuándo se cambió la clave por última vez, por el propio
             * usuario o por una recuperación por correo. Sale del historial
             * de accesos, que ya lo registraba: una columna diría lo mismo
             * y podría desincronizarse.
             */
            'passwordChangedAt' => UserLoginEvent::query()
                ->where('user_id', $request->user()->id)
                ->whereIn('event_type', [
                    LoginEventType::PasswordChanged->value,
                    LoginEventType::PasswordReset->value,
                ])
                ->latest('created_at')
                ->first(['created_at'])
                ?->created_at
                ->toIso8601String(),
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
     *
     * Solo el cambio de todos los días: con la clave temporal todavía puesta
     * el usuario no llega hasta acá, `ForcePasswordChange` lo lleva al
     * primer ingreso.
     */
    public function update(PasswordUpdateRequest $request, ChangeOwnPassword $changeOwnPassword): RedirectResponse
    {
        $changeOwnPassword->handle(
            $request->user(),
            (string) $request->validated('password'),
            $request->session()->getId(),
        );

        Toast::success(__('Contraseña actualizada.'));

        return back();
    }
}
