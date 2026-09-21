<?php

declare(strict_types=1);

namespace App\Support\Money;

use InvalidArgumentException;

/**
 * Importes como cadena decimal, de punta a punta.
 *
 * El modelo prohíbe el `float` en cualquier punto de la pila. Acá se
 * concentra lo que un archivo externo obliga a hacer con los importes que
 * llegan como texto: reconocerlos, normalizarlos a dos decimales y
 * sumarlos sin salir nunca del terreno decimal.
 *
 * Las operaciones usan `bcmath`, que trabaja sobre cadenas. Es la misma
 * decisión que ya toma `ExpedienteListItemData` al acumular haberes.
 */
final class Decimal
{
    public const SCALE = 2;

    /**
     * Interpreta un importe escrito al estilo argentino.
     *
     * Acepta `1.253.491,05`, `1253491,05`, `1253491.05` y `473191.2`.
     * Devuelve `null` si no es un número: eso es una fila que el parser
     * tiene que rechazar, no un cero que ensucie la contabilidad.
     *
     * @return numeric-string|null
     */
    public static function parse(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim($value);

        if ($clean === '') {
            return null;
        }

        $negative = str_starts_with($clean, '-');
        $clean = ltrim($clean, '+-');
        // Símbolos y espacios que a veces acompañan al número en el archivo.
        $clean = str_replace(['$', ' ', "\u{00A0}"], '', $clean);

        if ($clean === '') {
            return null;
        }

        $lastComma = strrpos($clean, ',');
        $lastDot = strrpos($clean, '.');

        /*
         * El separador decimal es el ÚLTIMO que aparece. `1.253.491,05`
         * usa la coma; `473191.2`, el punto. Decidirlo por posición y no
         * por el carácter es lo que permite leer los dos formatos que
         * exporta el banco con la misma función.
         */
        $decimalSeparator = match (true) {
            $lastComma !== false && $lastDot !== false => $lastComma > $lastDot ? ',' : '.',
            $lastComma !== false => ',',
            $lastDot !== false => '.',
            default => null,
        };

        if ($decimalSeparator === null) {
            $integer = $clean;
            $fraction = '';
        } else {
            $position = strrpos($clean, $decimalSeparator);
            /** @var int $position ya se comprobó que el separador existe */
            $integer = substr($clean, 0, $position);
            $fraction = substr($clean, $position + 1);

            /*
             * Tres dígitos detrás del separador, y el otro separador sin
             * aparecer en ninguna parte: son miles, no decimales.
             * `1.253.491` es un millón doscientos cincuenta y tres mil, no
             * mil doscientos cincuenta y tres con cuarenta y nueve.
             *
             * Cuando los dos separadores conviven no hay ambigüedad que
             * resolver: el último manda y este caso ni se evalúa.
             */
            $otherSeparator = $decimalSeparator === ',' ? '.' : ',';

            if (strlen($fraction) === 3 && ! str_contains($clean, $otherSeparator)) {
                $integer = $integer.$fraction;
                $fraction = '';
            }
        }

        $integer = str_replace([',', '.'], '', $integer);

        if ($integer === '') {
            $integer = '0';
        }

        if (! ctype_digit($integer) || ($fraction !== '' && ! ctype_digit($fraction))) {
            return null;
        }

        $normalized = $integer.($fraction === '' ? '' : '.'.$fraction);

        return self::scale($negative ? '-'.$normalized : $normalized);
    }

    /**
     * Lleva un decimal ya válido a dos posiciones, redondeando.
     *
     * @return numeric-string
     */
    public static function scale(string $value, int $scale = self::SCALE): string
    {
        // `bcadd` trunca en vez de redondear, así que el medio centavo se
        // resuelve antes de sumar. Un extracto nunca los trae, pero
        // truncar en silencio es la clase de error que aparece años
        // después y nadie puede explicar.
        return bcadd(self::round($value, $scale), '0', $scale);
    }

    /**
     * @return numeric-string
     */
    public static function abs(string $value): string
    {
        return self::numeric(ltrim(self::numeric($value), '-'));
    }

    public static function isNegative(string $value): bool
    {
        return bccomp(self::numeric($value), '0', self::SCALE) < 0;
    }

    /**
     * @return numeric-string
     */
    public static function add(string $a, string $b, int $scale = self::SCALE): string
    {
        return bcadd(self::numeric($a), self::numeric($b), $scale);
    }

    /**
     * @return numeric-string
     */
    public static function sub(string $a, string $b, int $scale = self::SCALE): string
    {
        return bcsub(self::numeric($a), self::numeric($b), $scale);
    }

    public static function equals(string $a, string $b, int $scale = self::SCALE): bool
    {
        return bccomp(self::numeric($a), self::numeric($b), $scale) === 0;
    }

    /**
     * @return numeric-string
     */
    private static function round(string $value, int $scale): string
    {
        $magnitude = self::numeric(ltrim(self::numeric($value), '-'));
        $negative = str_starts_with($value, '-');

        // Sumar medio en la última posición y truncar equivale a redondear
        // hacia arriba en el medio, sin pasar por float.
        $half = self::numeric('0.'.str_repeat('0', $scale).'5');
        $rounded = bcadd(bcadd($magnitude, $half, $scale + 1), '0', $scale);

        return $negative && bccomp($rounded, '0', $scale) !== 0
            ? self::numeric('-'.$rounded)
            : $rounded;
    }

    /**
     * Al estilo argentino: `1.253.491,05`.
     *
     * Es el gemelo en PHP de `money()` de `resources/js/lib/format.ts`, y
     * por el mismo motivo: agrupa operando sobre la cadena. Existe para
     * los pocos importes que el servidor tiene que escribir dentro de un
     * texto —el motivo de un rechazo, una advertencia— donde no hay un
     * componente del front que pueda formatearlos después.
     */
    public static function format(string $value, int $scale = self::SCALE): string
    {
        $scaled = self::scale($value, $scale);
        $negative = str_starts_with($scaled, '-');
        [$integer, $fraction] = array_pad(explode('.', ltrim($scaled, '-')), 2, '');

        $grouped = strrev(implode('.', str_split(strrev($integer), 3)));

        return ($negative ? '-' : '').$grouped.($scale > 0 ? ','.str_pad($fraction, $scale, '0') : '');
    }

    /**
     * Puerta de entrada a `bcmath`: lo que no es un número no pasa.
     *
     * No es una concesión al analizador estático. `bcadd` con una cadena
     * que no es numérica devuelve un resultado igual —tratándola como
     * cero— en vez de fallar, y un cero silencioso dentro de un cálculo de
     * saldos es exactamente el error que nadie encuentra después.
     *
     * @return numeric-string
     */
    private static function numeric(string $value): string
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("«{$value}» no es un importe.");
        }

        return $value;
    }
}
