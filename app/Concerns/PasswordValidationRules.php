<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::default(), 'confirmed'];
    }

    /**
     * Las reglas de una contraseña que reemplaza a otra del mismo usuario.
     *
     * Suma una sola cosa a la política: que no sea la que ya tiene. En el
     * primer ingreso es lo que importa —«cambiar» la temporal por sí misma
     * dejaría al administrador conociéndola para siempre—, y en el cambio
     * de todos los días es el mismo error, solo que menos grave.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string|Closure>
     */
    protected function replacementPasswordRules(User $user): array
    {
        return [
            ...$this->passwordRules(),
            function (string $attribute, mixed $value, Closure $fail) use ($user): void {
                if (is_string($value) && Hash::check($value, $user->password)) {
                    $fail(__('validation.custom.password.same_as_current'));
                }
            },
        ];
    }

    /**
     * Get the validation rules used to validate the current password.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function currentPasswordRules(): array
    {
        return ['required', 'string', 'current_password'];
    }
}
