<?php

declare(strict_types=1);

namespace App\Modules\Shared\Actions;

use App\Models\User;
use App\Modules\Shared\Enums\LoginEventType;

/**
 * El usuario cambia su propia contraseña.
 *
 * Hay dos puertas —el primer ingreso y «Mi cuenta › Seguridad»— y una sola
 * cosa que pasa detrás de las dos. Vive acá para que no se separen: si una
 * dejara de cerrar las otras sesiones o de escribir el historial, nada lo
 * haría notar.
 */
final class ChangeOwnPassword
{
    public function __construct(
        private readonly RecordLoginEvent $registrarAcceso,
        private readonly RevokeUserSessions $revocarSesiones,
    ) {}

    /**
     * @param  string  $currentSessionId  La sesión desde la que se hace el cambio, que se conserva.
     * @return bool Si el cambio era el obligatorio, el que baja la marca de la clave temporal.
     */
    public function handle(User $user, string $password, string $currentSessionId): bool
    {
        $eraObligatorio = $user->must_change_password;

        $user->update([
            'password' => $password,
            // Acá termina el período en que dos personas conocen la clave.
            'must_change_password' => false,
        ]);

        $this->registrarAcceso->handle(
            type: LoginEventType::PasswordChanged,
            user: $user,
            meta: $eraObligatorio ? ['reason' => 'cambio obligatorio'] : [],
        );

        /*
         * Cambiar la clave es lo primero que hace quien sospecha que otro
         * la conoce. Si las otras sesiones siguieran abiertas, el cambio no
         * lo sacaría a ese otro. La de esta pantalla se conserva.
         */
        $this->revocarSesiones->handle(
            $user,
            exceptSessionId: $currentSessionId,
            reason: 'contraseña cambiada',
        );

        return $eraObligatorio;
    }
}
