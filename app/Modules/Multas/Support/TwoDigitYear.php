<?php

declare(strict_types=1);

namespace App\Modules\Multas\Support;

use App\Support\BusinessDate;

/**
 * El año de dos dígitos de los papeles, en cuatro.
 *
 * El Excel escribe «/24» y también «/07». El pivote es el año próximo: hasta
 * ahí es este siglo, y lo de más arriba —«/95»— el anterior. Un año que ya
 * viene con cuatro dígitos se respeta.
 */
final class TwoDigitYear
{
    public static function expand(int $year): int
    {
        if ($year >= 100) {
            return $year;
        }

        $pivote = (BusinessDate::today()->year % 100) + 1;

        return $year <= $pivote ? 2000 + $year : 1900 + $year;
    }
}
