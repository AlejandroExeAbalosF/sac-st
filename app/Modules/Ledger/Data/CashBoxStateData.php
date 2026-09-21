<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Dónde está la plata de una caja a una fecha.
 *
 * Los cuatro números que el área mira primero. Los tres primeros dicen
 * **dónde está** —cajón, cheques, banco— y el cuarto **de quién es**, o
 * mejor dicho de quién todavía no se sabe: `UNASSIGNED_FUNDS` es la cola de
 * trabajo, y ponerlo al lado de los otros tres es lo que convierte cuatro
 * saldos en una respuesta.
 *
 * Todos son cadenas decimales. Ninguno se almacena: salen de sumar
 * `journal_lines`.
 */
#[TypeScript]
final class CashBoxStateData extends Data
{
    public function __construct(
        public int $cashBoxId,
        public string $code,
        public string $name,
        public string $currency,
        /** @var numeric-string */
        public string $cash,
        /** @var numeric-string */
        public string $cheques,
        /** @var numeric-string */
        public string $bank,
        /** @var numeric-string */
        public string $unassigned,
        /**
         * Efectivo que salió de la caja y el banco todavía no acreditó.
         *
         * Casi siempre es cero, y cuando no lo es explica por qué el arqueo
         * del día cierra con menos plata sin que haya salido un peso hacia
         * un beneficiario.
         *
         * @var numeric-string
         */
        public string $inTransit,
    ) {}
}
