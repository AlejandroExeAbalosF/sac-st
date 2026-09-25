<?php

declare(strict_types=1);

namespace App\Modules\Shared\Actions;

use App\Models\User;
use App\Modules\Shared\Support\UserManagementGuard;
use Illuminate\Support\Facades\DB;

/**
 * Corrección de la ficha de un usuario y de su rol.
 *
 * El `username` **no** se cambia acá ni en ningún lado: es con lo que queda
 * registrado cada intento de acceso en `user_login_events`, y renombrarlo
 * volvería ilegible el historial de quien lo tenía antes.
 *
 * El cargo sí se edita, y no es un dato decorativo: es lo que se congela en
 * `receipts.signed_by_title_snapshot` al pie de cada comprobante firmado.
 */
final class UpdateUser
{
    public function __construct(
        private readonly RecordAuditEvent $auditar,
        private readonly UserManagementGuard $guard,
    ) {}

    /**
     * @param  array{first_name: string, last_name: string, document_number: string, email: string, position?: string|null}  $attributes
     */
    public function handle(User $user, array $attributes, string $role, ?User $actor = null): User
    {
        $this->guard->assert($this->guard->denyManaging($user, $actor));

        if ($attributes['email'] !== $user->email) {
            $this->guard->assert($this->guard->denyCredentialChange($user, $actor));
        }

        $before = [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'document_number' => $user->document_number,
            'email' => $user->email,
            'position' => $user->position,
            'role' => $user->getRoleNames()->first(),
        ];

        DB::transaction(function () use ($user, $attributes, $role, $actor): void {
            // El rol se decide con el bloqueo tomado: la guarda del último
            // administrador cuenta, y dos cambios a la vez contarían mal.
            $this->guard->lockAdministrators();
            $this->guard->assert($this->guard->denyRole($role, $user, $actor));

            $user->fill($attributes);

            // Cambiar el correo vuelve a dejarlo sin verificar: el aviso de
            // recuperación tiene que llegar a una casilla comprobada.
            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }

            $user->save();

            // `syncRoles` y no `assignRole`: el sistema define un rol por
            // usuario, y asignar sin quitar acumularía permisos que nadie
            // recuerda haber dado.
            $user->syncRoles([$role]);
        });

        $after = [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'document_number' => $user->document_number,
            'email' => $user->email,
            'position' => $user->position,
            'role' => $role,
        ];

        [$antes, $despues] = RecordAuditEvent::diff($before, $after);

        if ($despues !== []) {
            $this->auditar->handle(
                action: 'usuario.actualizado',
                subject: $user,
                before: $antes,
                after: $despues,
                actorId: $actor?->id,
            );
        }

        return $user;
    }
}
