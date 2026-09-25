<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use App\Models\User;
use App\Modules\Shared\Enums\SystemRole;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Qué puede hacerle un usuario a otro desde la administración de usuarios.
 *
 * Las reglas vivían repartidas —una en cada Action— y lo que faltaba era
 * justo lo que cruza entre ellas: un administrador podía asignarse
 * `super-admin`, restablecerle la clave a otro administrador y entrar como
 * él, o quitarse el rol siendo el último. Acá están todas juntas, para que
 * la pantalla y las Actions pregunten lo mismo al mismo lugar.
 *
 * Cada método devuelve el motivo del rechazo, o `null` si se permite: la
 * pantalla lo usa para deshabilitar el control y la Action, con
 * `assert()`, para rechazar con el mismo texto.
 *
 * Un actor `null` es el sistema —un comando de consola—, y puede todo.
 */
final class UserManagementGuard
{
    /**
     * Los roles que este actor puede asignar.
     *
     * @return array<string, string> rol => descripción
     */
    public function assignableRoles(?User $actor): array
    {
        $catalogo = SystemRole::catalog();

        if (! $this->isSuperAdmin($actor)) {
            unset($catalogo[SystemRole::SuperAdmin->value]);
        }

        return $catalogo;
    }

    /**
     * Si el actor puede siquiera tocar la ficha.
     *
     * `super-admin` no es un rol operativo: salta controles para probar el
     * circuito. Que un administrador pudiera editarlo, desactivarlo o
     * restablecerle la clave era una forma de apropiarse de él.
     */
    public function denyManaging(User $target, ?User $actor): ?string
    {
        if ($actor === null || ! $this->isSuperAdmin($target) || $this->isSuperAdmin($actor)) {
            return null;
        }

        return 'Un super-admin solo lo gestiona otro super-admin.';
    }

    /**
     * Si el actor puede restablecerle la clave o cambiarle el correo.
     *
     * Las dos cosas son formas de entrar como el otro: con la clave
     * temporal en la mano, o recuperándola desde una casilla propia. Entre
     * administradores no se permiten, porque lo que el impostor hiciera
     * quedaría firmado por el otro. El administrador que pierde su clave la
     * recupera por correo o, si no hay correo, por consola
     * (`usuarios:restablecer-clave`), que deja el mismo registro.
     */
    public function denyCredentialChange(User $target, ?User $actor): ?string
    {
        // Lo propio se cambia desde Mi cuenta, que pide la contraseña actual:
        // por acá sería un atajo que se la saltea.
        if ($actor !== null && $actor->is($target)) {
            return 'Tu correo y tu contraseña se cambian desde Mi cuenta.';
        }

        if (($motivo = $this->denyManaging($target, $actor)) !== null) {
            return $motivo;
        }

        if ($this->isPeerAdministrator($target, $actor)) {
            return 'Entre administradores no se restablecen claves ni se cambian correos: '
                .'cada uno recupera la suya por correo o, si no hay correo, por consola.';
        }

        return null;
    }

    /**
     * Si el actor puede dejarle `$role` al usuario.
     *
     * `$target` es `null` en el alta.
     */
    public function denyRole(string $role, ?User $target, ?User $actor): ?string
    {
        if ($target !== null && $target->hasRole($role)) {
            return null;
        }

        if ($role === SystemRole::SuperAdmin->value && $actor !== null && ! $this->isSuperAdmin($actor)) {
            return 'Solo un super-admin puede asignar ese rol.';
        }

        return $target === null ? null : $this->denyRoleChange($target, $actor);
    }

    /**
     * Si el actor puede cambiarle el rol, sea cual sea el nuevo.
     */
    public function denyRoleChange(User $target, ?User $actor): ?string
    {
        if ($actor !== null && $actor->is($target)) {
            return 'No podés cambiar tu propio rol: lo cambia otro administrador.';
        }

        if (($motivo = $this->denyManaging($target, $actor)) !== null) {
            return $motivo;
        }

        if ($this->isPeerAdministrator($target, $actor)) {
            return 'Entre administradores no se cambian roles.';
        }

        if ($this->isLastActiveAdministrator($target)) {
            return 'Es el único administrador activo: designá otro antes de cambiarle el rol.';
        }

        return null;
    }

    /**
     * Si es el único administrador activo.
     *
     * Sin él nadie tiene `usuarios.*` ni `roles.gestionar`, y la
     * administración del sistema solo se recupera por consola.
     */
    public function isLastActiveAdministrator(User $user): bool
    {
        if (! $user->hasRole(SystemRole::Administrador->value)) {
            return false;
        }

        return ! User::query()
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->whereHas('roles', fn ($query) => $query->where('name', SystemRole::Administrador->value))
            ->exists();
    }

    /**
     * Ordena las operaciones que pueden dejar al sistema sin administrador.
     *
     * Comprobar «hay otro administrador activo» y después escribir no
     * alcanza si dos lo hacen a la vez: cada uno ve al otro activo y pasan
     * los dos. Con este bloqueo la segunda espera a que la primera termine
     * y comprueba con el resultado a la vista. Es el mismo que toma el
     * trigger `ensure_an_active_administrator`, así que el mensaje legible
     * y el rechazo de la base deciden sobre los mismos datos.
     *
     * Dura hasta el final de la transacción, por eso la exige.
     */
    public function lockAdministrators(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('El bloqueo de administradores exige una transacción.');
        }

        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['sacst.active_administrators']);
    }

    public function assert(?string $motivo): void
    {
        if ($motivo !== null) {
            throw new RuntimeException($motivo);
        }
    }

    private function isPeerAdministrator(User $target, ?User $actor): bool
    {
        return $actor !== null
            && ! $actor->is($target)
            && ! $this->isSuperAdmin($actor)
            && $target->hasRole(SystemRole::Administrador->value);
    }

    private function isSuperAdmin(?User $user): bool
    {
        return $user?->hasRole(SystemRole::SuperAdmin->value) ?? false;
    }
}
