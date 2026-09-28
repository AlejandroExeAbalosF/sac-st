<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use App\Modules\Shared\Actions\RevokeUserSessions;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->replacementPasswordRules($user),
        ])->validate();

        $user->forceFill([
            'password' => $input['password'],
            /*
             * La clave la eligió el titular, desde el enlace que llegó a su
             * correo: nadie más la conoce. Si todavía tenía puesta la marca
             * de la temporal, dejarla lo mandaría al primer ingreso a
             * elegir otra vez lo que acaba de elegir.
             */
            'must_change_password' => false,
        ])->save();

        /*
         * Quien recupera la clave por correo casi siempre lo hace porque
         * perdió el control de la anterior: las sesiones que hayan quedado
         * abiertas con ella no pueden sobrevivirla.
         */
        app(RevokeUserSessions::class)->handle($user, reason: 'contraseña recuperada por correo');
    }
}
