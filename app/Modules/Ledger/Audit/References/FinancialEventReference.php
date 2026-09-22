<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Audit\References;

use App\Modules\Shared\Audit\AuditReferenceResolver;

/**
 * Los asientos que un evento menciona.
 *
 * No hay nombre que buscar: un asiento se identifica por su número, y
 * decirlo así es más claro que un id suelto al lado de «Asiento de ajuste».
 */
final class FinancialEventReference implements AuditReferenceResolver
{
    public function fields(): array
    {
        return ['financial_event_id', 'adjustment_event_id', 'reversal_event_id'];
    }

    public function labels(array $ids): array
    {
        $etiquetas = [];

        foreach ($ids as $id) {
            $etiquetas[$id] = "Asiento n.º {$id}";
        }

        return $etiquetas;
    }
}
