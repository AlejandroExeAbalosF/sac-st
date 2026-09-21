<?php

declare(strict_types=1);

namespace App\Modules\Shared\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Tipos de evento del historial de accesos.
 *
 * Los valores están replicados en un CHECK de `user_login_events`: si acá
 * se agrega un caso, hay que agregarlo también en una migración. Es el
 * precio de que la base no dependa de que el código se porte bien.
 */
#[TypeScript]
enum LoginEventType: string
{
    case LoginSuccess = 'login_success';
    case LoginFailed = 'login_failed';
    case Logout = 'logout';
    case PasswordReset = 'password_reset';
    case PasswordChanged = 'password_changed';
    case TwoFactorChallenged = 'two_factor_challenged';
    case TwoFactorFailed = 'two_factor_failed';
    case SessionRevoked = 'session_revoked';
    case Lockout = 'lockout';

    public function label(): string
    {
        return match ($this) {
            self::LoginSuccess => 'Ingreso',
            self::LoginFailed => 'Ingreso fallido',
            self::Logout => 'Salida',
            self::PasswordReset => 'Contraseña restablecida',
            self::PasswordChanged => 'Contraseña cambiada',
            self::TwoFactorChallenged => 'Segundo factor solicitado',
            self::TwoFactorFailed => 'Segundo factor fallido',
            self::SessionRevoked => 'Sesión cerrada remotamente',
            self::Lockout => 'Cuenta bloqueada por intentos',
        };
    }

    /** Un evento que merece aparecer resaltado en la pantalla de auditoría. */
    public function isSuspicious(): bool
    {
        return in_array($this, [self::LoginFailed, self::TwoFactorFailed, self::Lockout], true);
    }
}
