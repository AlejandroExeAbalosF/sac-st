<?php

declare(strict_types=1);

namespace App\Modules\Banking\Audit\References;

use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Shared\Audit\AuditReferenceResolver;

/**
 * Una referencia bancaria legible, sin perder el id cuando el banco no
 * informó número de operación.
 */
final class BankTransactionReference implements AuditReferenceResolver
{
    public static function labelFor(BankTransaction $movimiento): string
    {
        return $movimiento->operation_id
            ? 'Operación '.$movimiento->operation_id
            : 'Movimiento n.º '.$movimiento->id;
    }

    public function fields(): array
    {
        return ['bank_transaction_id'];
    }

    public function labels(array $ids): array
    {
        $etiquetas = [];

        foreach (BankTransaction::query()->whereIn('id', $ids)->get(['id', 'operation_id']) as $movimiento) {
            $etiquetas[$movimiento->id] = self::labelFor($movimiento);
        }

        return $etiquetas;
    }
}
