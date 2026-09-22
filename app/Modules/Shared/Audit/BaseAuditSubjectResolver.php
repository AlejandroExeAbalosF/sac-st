<?php

declare(strict_types=1);

namespace App\Modules\Shared\Audit;

use App\Models\User;

/**
 * Lo que casi todos los resolvers dicen igual.
 *
 * Un sujeto sin valores propios que traducir no tiene por qué declararlo,
 * y el enlace se ofrece solo a quien puede abrir el destino.
 */
abstract class BaseAuditSubjectResolver implements AuditSubjectResolver
{
    public function valueLabels(): array
    {
        return [];
    }

    public function moneyFields(): array
    {
        return [];
    }

    protected function can(?User $viewer, string $permission): bool
    {
        return $viewer?->can($permission) ?? false;
    }
}
