<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Cómo está la caja hoy, para quien recién abre el sistema.
 *
 * Es la primera pregunta de la mañana —**cuánto hay y en qué estado está
 * el día**— contestada sin entrar a Caja. Los saldos son los mismos que
 * muestra esa pantalla porque salen del mismo `CashBalance`: no hay una
 * segunda cuenta que pueda dar distinto.
 */
#[TypeScript]
final class CashDayStatusData extends Data
{
    public function __construct(
        public CashBoxStateData $balances,
        /** La fecha operativa a la que corresponden los saldos. */
        public string $date,
        /**
         * Los libros no se abrieron nunca.
         *
         * Mientras esto sea verdadero todos los saldos son cero y el
         * primer arqueo daría una diferencia igual al saldo histórico
         * completo. Es lo único que importa de la caja hasta que se
         * resuelva.
         */
        public bool $needsOpening,
        /** Arqueos registrados hoy. Cero significa que nadie contó todavía. */
        public int $countsToday,
        /** `null` si el día no tiene cierre; si no, su estado. */
        public ?string $closingStatus,
        public string $href,
    ) {}
}
