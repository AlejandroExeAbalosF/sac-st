<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use App\Modules\Shared\Actions\RecordLoginEvent;
use App\Modules\Shared\Enums\LoginEventType;
use App\Modules\Shared\Enums\LoginFailureReason;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Responses\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkeys;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * «Olvidé mi contraseña» responde lo mismo exista o no la cuenta.
         *
         * Fortify devuelve un error cuando el correo no está registrado, y
         * eso convierte la pantalla en un oráculo: cualquiera averigua qué
         * casillas tienen usuario. El texto ya era neutro; la forma de la
         * respuesta no.
         */
        $this->app->bind(
            FailedPasswordResetLinkRequestResponse::class,
            fn (): SuccessfulPasswordResetLinkRequestResponse => new SuccessfulPasswordResetLinkRequestResponse(
                PasswordBroker::RESET_LINK_SENT,
            ),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureAuthentication();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Autenticación por nombre de usuario, con bloqueo de usuarios inactivos.
     *
     * Un usuario dado de baja nunca se elimina —sus acciones pasadas tienen
     * que seguir siendo atribuibles—, así que la baja se resuelve acá: las
     * credenciales pueden ser correctas y el ingreso se rechaza igual, con
     * un mensaje que le dice al operador a quién recurrir.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $username = Str::lower(trim((string) $request->input(Fortify::username())));

            $user = User::query()->where('username', $username)->first();

            if ($user === null || ! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }

            if (! $user->isActive()) {
                app(RecordLoginEvent::class)->handle(
                    type: LoginEventType::LoginFailed,
                    user: $user,
                    usernameAttempted: $username,
                    reason: LoginFailureReason::AccountDisabled,
                    request: $request,
                );

                Log::channel('security')->warning('Ingreso bloqueado: usuario inactivo', [
                    'user_id' => $user->id,
                    'username' => $username,
                    'ip' => $request->ip(),
                ]);

                throw ValidationException::withMessages([
                    Fortify::username() => 'Tu usuario está inactivo. Comunicate con el administrador del sistema.',
                ]);
            }

            return $user;
        });

        /*
         * La passkey no pasa por `authenticateUsing`, así que el bloqueo de
         * arriba no la alcanza: un usuario dado de baja volvía a entrar con
         * la suya. `EnsureAccountIsUsable` lo sacaría en el pedido
         * siguiente; esto evita que llegue a entrar.
         */
        Passkeys::authorizeLoginUsing(function (Request $request, PasskeyUser $user): bool {
            if ($user instanceof User && $user->isActive()) {
                return true;
            }

            app(RecordLoginEvent::class)->handle(
                type: LoginEventType::LoginFailed,
                user: $user instanceof User ? $user : null,
                usernameAttempted: $user instanceof User ? $user->username : null,
                reason: LoginFailureReason::AccountDisabled,
                request: $request,
            );

            return false;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('mi-cuenta/confirmar-contrasena'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        /*
         * Pedir el enlace de recuperación manda un correo: sin tope, sirve
         * para inundar una casilla o para probar correos a ciegas. Se
         * engancha a todas las rutas de Fortify (ver `config/fortify.php`)
         * y solo actúa sobre las dos de recuperación.
         */
        RateLimiter::for('password-reset', function (Request $request) {
            if (! $request->isMethod('POST') || ! $request->routeIs('password.email', 'password.update')) {
                return Limit::none();
            }

            $limites = [Limit::perMinute(5)->by('ip:'.$request->ip())];

            // Por casilla, solo el pedido del enlace: al elegir la clave
            // nueva, tres errores de tipeo no deberían dejar a nadie
            // esperando un cuarto de hora.
            if ($request->routeIs('password.email')) {
                $limites[] = Limit::perMinutes(15, 3)->by('email:'.Str::lower((string) $request->input('email')));
            }

            return $limites;
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
