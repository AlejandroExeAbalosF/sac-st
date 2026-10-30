<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Events;

use App\Modules\Ledger\Models\CashBookOpening;

/**
 * Se abrió el libro de una caja en una moneda.
 *
 * Se despacha dentro de la transacción de la apertura: lo que cuelga de ella
 * —la primera cuenta habilitada de la caja, en Banking— queda en el mismo
 * acto o no queda. Ledger no sabe quién escucha.
 */
final readonly class CashBookOpened
{
    public function __construct(
        public CashBookOpening $opening,
        public int $actorId,
    ) {}
}
