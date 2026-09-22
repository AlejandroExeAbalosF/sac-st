<?php

declare(strict_types=1);

namespace App\Modules\Shared\Audit;

/**
 * Traduce a palabras los ids que apuntan a una entidad.
 *
 * Va por nombre de campo y no por sujeto: `bank_transaction_id` quiere
 * decir lo mismo en una recepción que en un traslado, y lo sabe traducir
 * quien es dueño de los movimientos bancarios. Así Ledger puede mostrar
 * «Operación 4521» sin conocer Banking.
 */
interface AuditReferenceResolver
{
    /**
     * Los campos que apuntan a esta entidad.
     *
     * @return list<string>
     */
    public function fields(): array;

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    public function labels(array $ids): array;
}
