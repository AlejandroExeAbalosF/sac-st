<?php

declare(strict_types=1);

namespace App\Modules\Shared\Actions;

use App\Models\User;
use App\Modules\Shared\Support\UserManagementGuard;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Alta y baja operativa de un usuario.
 *
 * **Un usuario nunca se borra.** Registró recepciones, emitió recibos o
 * validó egresos, y esos actos tienen que seguir siendo atribuibles para
 * siempre; borrarlo dejaría comprobantes firmados por nadie. Lo que se hace
 * es impedirle entrar, sin tocar una línea de su historial.
 *
 * Dos puertas cerradas, y las dos por lo mismo —dejar el sistema sin quien
 * pueda administrarlo—: nadie se desactiva a sí mismo, y no se desactiva al
 * último administrador activo. Sin esta segunda, un descuido deja el
 * sistema sin forma de dar de alta a nadie, ni siquiera a sí mismo.
 */
final class SetUserActive
{
    public function __construct(
        private readonly RecordAuditEvent $auditar,
        private readonly RevokeUserSessions $revocarSesiones,
        private readonly UserManagementGuard $guard,
    ) {}

    public function handle(User $user, bool $active, ?User $actor = null): User
    {
        if ($user->is_active === $active) {
            return $user;
        }

        $this->guard->assert($this->guard->denyManaging($user, $actor));

        DB::transaction(function () use ($user, $active, $actor): void {
            if (! $active) {
                // Antes de contar: si otro administrador se está
                // desactivando a la vez, esta espera y lo ve.
                $this->guard->lockAdministrators();
                $this->guardAgainstSelfDeactivation($user, $actor);
                $this->guardAgainstLastAdministrator($user);
            }

            $user->update(['is_active' => $active]);

            // Desactivar sin cerrar sus sesiones no lo saca del sistema: la
            // sesión abierta sigue funcionando hasta que expire.
            if (! $active) {
                $this->revocarSesiones->handle($user, reason: 'usuario desactivado');
            }

            $this->auditar->handle(
                action: $active ? 'usuario.activado' : 'usuario.desactivado',
                subject: $user,
                before: ['is_active' => ! $active],
                after: ['is_active' => $active],
                actorId: $actor?->id,
            );
        });

        return $user;
    }

    private function guardAgainstSelfDeactivation(User $user, ?User $actor): void
    {
        if ($actor !== null && $actor->id === $user->id) {
            throw new RuntimeException('No podés desactivar tu propio usuario.');
        }
    }

    private function guardAgainstLastAdministrator(User $user): void
    {
        if ($this->guard->isLastActiveAdministrator($user)) {
            throw new RuntimeException(
                'Es el único administrador activo: designá otro antes de desactivarlo.'
            );
        }
    }
}
