<?php

declare(strict_types=1);

namespace App\Modules\Shared\Audit\Subjects;

use App\Models\User;
use App\Modules\Shared\Audit\AuditSubjectDescription;
use App\Modules\Shared\Audit\BaseAuditSubjectResolver;

final class UserSubject extends BaseAuditSubjectResolver
{
    public function subjectType(): string
    {
        return 'User';
    }

    public function label(): string
    {
        return 'Usuario';
    }

    public function fields(): array
    {
        return [
            'first_name' => 'Nombre',
            'last_name' => 'Apellido',
            'username' => 'Usuario',
            'document_number' => 'DNI',
            'email' => 'Correo electrónico',
            'position' => 'Cargo',
            'role' => 'Rol',
            'is_active' => 'Activo',
        ];
    }

    public function describe(array $ids, ?User $viewer): array
    {
        $puedeVer = $this->can($viewer, 'usuarios.ver');

        return User::query()
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'username'])
            ->mapWithKeys(fn (User $user): array => [$user->id => new AuditSubjectDescription(
                "{$user->name} ({$user->username})",
                $puedeVer ? route('configuracion.usuarios.index', ['buscar' => $user->username]) : null,
            )])
            ->all();
    }
}
