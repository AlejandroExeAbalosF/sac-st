<?php

declare(strict_types=1);

namespace App\Modules\Shared\Data;

use App\Models\User;
use App\Modules\Shared\Support\UserManagementGuard;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un usuario tal como lo lista la pantalla de administración.
 *
 * Incluye `mustChangePassword` porque la lista es donde el administrador
 * verifica que un alta reciente ya haya entrado: mientras la marca siga
 * puesta, la contraseña que él conoce todavía sirve.
 */
#[TypeScript]
final class UserListItemData extends Data
{
    public function __construct(
        public int $id,
        /** «Apellido, Nombre», tal como lo arma la base. */
        public string $name,
        public string $firstName,
        public string $lastName,
        public string $username,
        public string $documentNumber,
        public ?string $email,
        public ?string $position,
        public ?string $role,
        public bool $isActive,
        public bool $mustChangePassword,
        /** ISO 8601 en UTC; el front lo presenta en hora de Salta. */
        public ?string $lastLoginAt,
        /**
         * Por qué quien mira no puede tocar esta ficha, o `null` si puede.
         *
         * Los tres motivos salen de `UserManagementGuard`, el mismo lugar
         * que después rechaza la operación: la pantalla deshabilita con el
         * mismo texto que el servidor devolvería.
         */
        public ?string $lockedReason = null,
        /** Por qué no puede restablecerle la clave ni cambiarle el correo. */
        public ?string $credentialsLockedReason = null,
        /** Por qué no puede cambiarle el rol. */
        public ?string $roleLockedReason = null,
    ) {}

    public static function fromModel(User $user, ?User $viewer = null, ?UserManagementGuard $guard = null): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            firstName: $user->first_name,
            lastName: $user->last_name,
            username: $user->username,
            documentNumber: $user->document_number,
            email: $user->email,
            position: $user->position,
            role: $user->getRoleNames()->first(),
            isActive: $user->is_active,
            mustChangePassword: $user->must_change_password,
            lastLoginAt: $user->last_login_at?->toIso8601String(),
            lockedReason: $guard?->denyManaging($user, $viewer),
            credentialsLockedReason: $guard?->denyCredentialChange($user, $viewer),
            roleLockedReason: $guard?->denyRoleChange($user, $viewer),
        );
    }
}
