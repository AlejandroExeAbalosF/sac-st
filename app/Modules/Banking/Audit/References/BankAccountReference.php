<?php

declare(strict_types=1);

namespace App\Modules\Banking\Audit\References;

use App\Modules\Banking\Models\BankAccount;
use App\Modules\Shared\Audit\AuditReferenceResolver;

/**
 * El nombre de las cuentas del organismo que un evento menciona.
 */
final class BankAccountReference implements AuditReferenceResolver
{
    public function fields(): array
    {
        return ['bank_account_id'];
    }

    public function labels(array $ids): array
    {
        /** @var array<int, string> $etiquetas */
        $etiquetas = BankAccount::query()->whereIn('id', $ids)->pluck('label', 'id')->all();

        return $etiquetas;
    }
}
