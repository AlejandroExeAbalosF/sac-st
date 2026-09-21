<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un mes en la vista de año.
 *
 * **No existe cierre anual**, y por eso el año es solo navegación: doce
 * celdas que dicen cómo viene cada mes y llevan adentro. Un cierre anual
 * trabaría un año entero de operaciones retroactivas y produciría un libro
 * de quinientas hojas; el área cierra por día y archiva por mes.
 *
 * `daysClosed` sobre `daysWithMovements` es la única cifra de avance que
 * importa: dice si el mes está al día o quedaron jornadas sin cerrar.
 */
#[TypeScript]
final class CalendarMonthData extends Data
{
    public function __construct(
        public int $month,
        public string $label,
        /** Si el mes tiene su cierre mensual hecho. */
        public bool $closed,
        public ?int $closingId,
        public bool $hasSheet,
        public int $daysClosed,
        public int $daysWithMovements,
        /**
         * El saldo con el que termina el mes: el del cierre mensual si
         * existe, y si no el del último día cerrado.
         *
         * @var numeric-string|null
         */
        public ?string $closingCash,
        public bool $isFuture,
    ) {}
}
