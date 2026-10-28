<?php

declare(strict_types=1);

namespace App\Modules\Multas\Support;

use App\Support\BusinessDate;

/**
 * El año de dos dígitos de los papeles, en cuatro.
 *
 * Si se sabe de cuándo es el papel —la fecha de la resolución o del acta—,
 * el siglo es el que deja el año más cerca de esa fecha: con fecha de 2026,
 * «/29» es 2029 y «/98» es 1998. Adivinar el siglo con una regla fija cuando
 * la fecha está a mano daba 1929 y la base lo rechazaba.
 *
 * Sin fecha —el número de expediente, o un acta sin fecha y sin resolución
 * de referencia— el pivote es el año próximo: hasta ahí es este siglo, y lo
 * de más arriba —«/95»— el anterior. Un año que ya viene con cuatro dígitos
 * se respeta.
 */
final class TwoDigitYear
{
    /**
     * Los años que acepta la base para expedientes y documentos
     * (`fine_cases_year_check`, `fine_documents_year_check`). Un número que
     * cae afuera —«/29» se lee 1929— se rechaza con un mensaje antes de
     * llegar a la base.
     */
    public const MIN = 1990;

    public const MAX = 2100;

    public static function inRange(int $year): bool
    {
        return $year >= self::MIN && $year <= self::MAX;
    }

    /** Lo que se le dice al operador cuando el año leído cae fuera del rango. */
    public static function outOfRangeMessage(string $papel, int $year): string
    {
        return "{$papel} queda con el año {$year}, y se aceptan años de ".self::MIN.' a '.self::MAX
            .'. Revisá el número, o escribí el año con cuatro cifras.';
    }

    public static function expand(int $year, ?int $reference = null): int
    {
        if ($year >= 100) {
            return $year;
        }

        if ($reference !== null) {
            $siglo = intdiv($reference, 100) * 100;
            $candidatos = [$siglo - 100 + $year, $siglo + $year, $siglo + 100 + $year];
            usort($candidatos, fn (int $a, int $b): int => abs($a - $reference) <=> abs($b - $reference));

            return $candidatos[0];
        }

        // Sin fecha: del siglo en curso hasta el año próximo, y del anterior por encima.
        $hoy = BusinessDate::today()->year;
        $siglo = intdiv($hoy, 100) * 100;

        return $year <= ($hoy % 100) + 1 ? $siglo + $year : $siglo - 100 + $year;
    }
}
