<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

/**
 * De dónde viene el dinero de una recepción.
 *
 * La base lo deduce del tipo de evento y lo exige
 * (`fund_receipts_origin_matches_event`), así que no es una etiqueta: decide
 * por qué vía se asigna. Lo que entró por el circuito está en
 * `UNASSIGNED_FUNDS` hasta que se asigna; lo del sistema anterior está en
 * `LEGACY_FUNDS`, y se asigna apartándolo.
 */
enum FundReceiptOrigin: string
{
    /** Entró por el circuito: mostrador, extracto o ticket. */
    case Received = 'received';

    /** Un cheque de la cartera que se declaró al abrir los libros. */
    case Opening = 'opening';

    /** Efectivo o un depósito directo apartados del saldo anterior. */
    case Legacy = 'legacy';

    /** Si es plata del sistema anterior, que se asigna con un apartado. */
    public function isLegacy(): bool
    {
        return $this !== self::Received;
    }
}
