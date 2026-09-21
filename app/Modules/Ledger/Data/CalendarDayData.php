<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un día en el calendario de caja.
 *
 * **`state` no es el estado del cierre**: es lo que hay que saber de un
 * vistazo mirando una grilla de treinta celdas. Un día cerrado y un día sin
 * un solo movimiento se ven distintos aunque los dos estén «bien», y un día
 * con movimientos sin cerrar es el único que pide algo.
 */
#[TypeScript]
final class CalendarDayData extends Data
{
    public function __construct(
        public string $date,
        public int $day,
        /** Si cae dentro del mes que se está mirando o es relleno de la grilla. */
        public bool $inMonth,
        public bool $isToday,
        public bool $isWeekend,
        /**
         * `closed` · `reopened` · `pending` —hubo movimientos y no se cerró—
         * · `quiet` —ni movimientos ni cierre— · `future`.
         */
        public string $state,
        public bool $hasMovements,
        /** Hubo asientos, pero todos son reversiones; puede no haber comprobantes. */
        public bool $onlyReversals,
        /** @var numeric-string|null */
        public ?string $closingCash,
        public ?int $closingId,
        /** Si el día tiene arqueo, y si cuadró. Null cuando no hay. */
        public ?bool $countBalanced,
        /** El arqueo cuadró pero no se contó el cajón entero. */
        public bool $partiallyCounted,
        public bool $hasSheet,
    ) {}
}
