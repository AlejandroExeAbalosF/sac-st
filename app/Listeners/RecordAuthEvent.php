<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Modules\Shared\Actions\RecordLoginEvent;
use App\Modules\Shared\Enums\LoginEventType;
use App\Modules\Shared\Enums\LoginFailureReason;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Laravel\Fortify\Fortify;

/**
 * Traduce los eventos de autenticación de Laravel a filas del historial.
 *
 * Está en `app/Listeners` y no dentro de un módulo a propósito: escucha
 * eventos del framework, no del dominio.
 *
 * Los métodos se llaman `onX` y no `handleX` por una razón concreta: el
 * descubrimiento automático de eventos de Laravel registra todo método de
 * `app/Listeners` cuyo nombre empiece con `handle`, deduciendo el evento
 * del tipo del parámetro. Con ese nombre, cada evento quedaba registrado
 * dos veces —una por descubrimiento y otra por el `Event::listen` de
 * AppServiceProvider— y el historial guardaba el ingreso duplicado.
 * El registro explícito es el que vale.
 */
final class RecordAuthEvent
{
    public function __construct(
        private readonly RecordLoginEvent $record,
    ) {}

    public function onLogin(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $this->record->handle(
            type: LoginEventType::LoginSuccess,
            user: $user,
            usernameAttempted: $user->username,
        );

        // `last_login_at` es un dato de conveniencia para el listado de
        // usuarios; la verdad histórica vive en user_login_events.
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
    }

    public function onFailed(Failed $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;
        $attempted = $this->attemptedUsername($event->credentials);

        $this->record->handle(
            type: LoginEventType::LoginFailed,
            user: $user,
            usernameAttempted: $attempted,
            reason: $user === null
                ? LoginFailureReason::UnknownUsername
                : LoginFailureReason::InvalidCredentials,
        );
    }

    public function onLogout(Logout $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $this->record->handle(
            type: LoginEventType::Logout,
            user: $user,
            usernameAttempted: $user->username,
        );
    }

    public function onLockout(Lockout $event): void
    {
        $this->record->handle(
            type: LoginEventType::Lockout,
            usernameAttempted: $this->attemptedUsername($event->request->all()),
            reason: LoginFailureReason::TooManyAttempts,
        );
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $this->record->handle(
            type: LoginEventType::PasswordReset,
            user: $user,
            usernameAttempted: $user->username,
        );
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function attemptedUsername(array $credentials): ?string
    {
        $value = $credentials[Fortify::username()] ?? null;

        return is_string($value) && $value !== '' ? mb_substr($value, 0, 60) : null;
    }
}
