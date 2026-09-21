<?php

declare(strict_types=1);

namespace App\Modules\Shared\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum LoginFailureReason: string
{
    case InvalidCredentials = 'invalid_credentials';
    case AccountDisabled = 'account_disabled';
    case UnknownUsername = 'unknown_username';
    case TooManyAttempts = 'too_many_attempts';
    case InvalidTwoFactorCode = 'invalid_two_factor_code';

    public function label(): string
    {
        return match ($this) {
            self::InvalidCredentials => 'Credenciales incorrectas',
            self::AccountDisabled => 'Usuario inactivo',
            self::UnknownUsername => 'Usuario inexistente',
            self::TooManyAttempts => 'Demasiados intentos',
            self::InvalidTwoFactorCode => 'Código de verificación incorrecto',
        };
    }
}
