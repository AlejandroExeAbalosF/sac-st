<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Audit\References;

use App\Modules\Shared\Audit\AuditReferenceResolver;

/**
 * Las recepciones que un evento menciona, por su número.
 */
final class FundReceiptReference implements AuditReferenceResolver
{
    public function fields(): array
    {
        return ['fund_receipt_id'];
    }

    public function labels(array $ids): array
    {
        $etiquetas = [];

        foreach ($ids as $id) {
            $etiquetas[$id] = "Recepción n.º {$id}";
        }

        return $etiquetas;
    }
}
