<?php

declare(strict_types=1);

namespace App\Modules\Shared\Actions;

use App\Models\User;
use App\Modules\Shared\Enums\LoginEventType;
use App\Support\TemporaryPassword;
use RuntimeException;

/**
 * Restablece la contraseña de un usuario que no puede entrar.
 *
 * Es el mismo trato que el alta: contraseña temporal, mostrada una vez, con
 * cambio obligatorio en el ingreso siguiente. Y cierra todas sus sesiones,
 * porque si se está restableciendo la clave es justamente porque hay dudas
 * sobre quién la tiene.
 *
 * **Nadie se lo hace a sí mismo**, y no es una formalidad: es la puerta que
 * deja al administrador afuera de su propio sistema. Se autogenera una clave
 * que se muestra una sola vez, se le cierran todas las sesiones —incluida la
 * que está usando— y si el diálogo se cierra sin copiarla no queda nadie que
 * pueda rescatarlo, porque rescatarlo exige entrar como administrador. Pasó
 * en desarrollo antes de que existiera esta guarda.
 *
 * Para cambiar la contraseña propia está «Mi cuenta › Seguridad», que pide la
 * actual y deja elegir la nueva: sin clave dictada por nadie y sin perder la
 * sesión. Es el mismo criterio con el que `SetUserActive` impide que alguien
 * se desactive a sí mismo.
 */
final class ResetUserPassword
{
    public function __construct(
        private readonly RecordAuditEvent $auditar,
        private readonly RecordLoginEvent $registrarAcceso,
        private readonly RevokeUserSessions $revocarSesiones,
    ) {}

    /**
     * @return string La clave en claro. No se guarda ni se vuelve a mostrar.
     */
    public function handle(User $user, ?User $actor = null): string
    {
        if ($actor !== null && $actor->id === $user->id) {
            throw new RuntimeException(
                'No podés restablecer tu propia contraseña: cambiala desde Mi cuenta › Seguridad.'
            );
        }

        $password = TemporaryPassword::generate();

        $user->update([
            'password' => $password,
            'must_change_password' => true,
        ]);

        $this->revocarSesiones->handle($user, reason: 'contraseña restablecida');

        $this->registrarAcceso->handle(
            type: LoginEventType::PasswordReset,
            user: $user,
            meta: ['reset_by' => $actor->username ?? 'sistema'],
        );

        $this->auditar->handle(
            action: 'usuario.password_restablecida',
            subject: $user,
            actorId: $actor?->id,
        );

        return $password;
    }
}
