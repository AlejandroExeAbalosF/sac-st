<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;

/**
 * La contraseña que reemplaza a la temporal.
 *
 * No pide la actual, y no es una concesión: quien acaba de entrar la tipeó
 * hace segundos, y pedírsela de nuevo solo le suma un campo donde
 * equivocarse con una clave que no eligió. Lo que ese campo protegía —la
 * sesión que quedó abierta y otra persona encontró— sigue cubierto, pero por
 * el reloj: pasado `auth.initial_password_timeout` desde que se confirmó la
 * contraseña, se vuelve a pedir.
 */
class InitialPasswordRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Si la sesión ya no prueba que quien está frente a la pantalla es quien
     * entró con la temporal.
     *
     * Lee la misma marca que `RequirePassword` —la deja el login, ver
     * `ConfirmPasswordOnLogin`—, con una ventana propia y mucho más corta.
     */
    public static function needsCurrentPassword(Request $request): bool
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        return time() - $confirmedAt > (int) config('auth.initial_password_timeout');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return [
            'current_password' => self::needsCurrentPassword($this)
                ? $this->currentPasswordRules()
                : ['exclude'],
            'password' => $this->replacementPasswordRules($user),
        ];
    }
}
