<?php

declare(strict_types=1);

namespace App\Support\Money;

/**
 * El importe en letras, como lo exige el recibo.
 *
 * El formulario de papel dice «Son Pesos:» y una línea para escribirlo a
 * mano. No es un adorno del comprobante: es la comprobación que atrapa un
 * cero de más. Repetir el número con puntos —«10.000.000,00»— obliga a
 * contar grupos de tres, que es exactamente el acto en el que se falla;
 * «diez millones» y «cien millones» no se parecen en nada.
 *
 * **Es el gemelo en PHP de `amountInWords()` de
 * `resources/js/lib/amount-words.ts`.** Los dos existen porque se usan en
 * momentos distintos: el del front avisa mientras el operador tipea un
 * importe —ahí no se puede ir al servidor por cada tecla— y este imprime
 * el comprobante, que se genera del lado del servidor. Es el mismo
 * criterio que ya rige entre `Decimal::format()` y `money()`.
 *
 * **Si uno cambia, el otro también.** Los dos se prueban con los mismos
 * casos, en `tests/Unit/AmountInWordsTest.php` y en `amount-words.test.ts`.
 */
final class AmountInWords
{
    /** @var list<string> */
    private const UNIDADES = [
        'cero', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete',
        'ocho', 'nueve', 'diez', 'once', 'doce', 'trece', 'catorce', 'quince',
        'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve', 'veinte',
        'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco',
        'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve',
    ];

    /** @var list<string> */
    private const DECENAS = [
        '', '', '', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta',
        'ochenta', 'noventa',
    ];

    /** @var list<string> */
    private const CENTENAS = [
        '', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos',
        'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos',
    ];

    /**
     * `'1204500.50'` → `'un millón doscientos cuatro mil quinientos con
     * cincuenta centavos'`.
     *
     * Devuelve cadena vacía cuando no hay nada que decir o cuando el
     * importe excede lo que se puede leer en voz alta sin perder el hilo.
     */
    public static function for(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        $normalizado = Decimal::parse($value);

        if ($normalizado === null) {
            return '';
        }

        $negativo = str_starts_with($normalizado, '-');
        [$entero, $centavos] = array_pad(explode('.', ltrim($normalizado, '-')), 2, '00');

        /*
         * Doce dígitos son novecientos noventa y nueve mil millones: más
         * allá el número deja de ser legible y el eco no aporta.
         */
        if (strlen($entero) > 12) {
            return '';
        }

        $pesos = self::entero((int) $entero);
        $partes = [$negativo ? "menos {$pesos}" : $pesos];

        if ($centavos !== '00') {
            $partes[] = 'con '.self::entero((int) $centavos).' centavos';
        }

        return implode(' ', $partes);
    }

    /** «uno» se apocopa delante de «mil» y de «millones»: veintiún mil. */
    private static function apocopar(string $texto): string
    {
        return preg_replace('/uno$/u', 'ún', $texto) ?? $texto;
    }

    private static function hasta999(int $n): string
    {
        if ($n === 0) {
            return '';
        }

        if ($n === 100) {
            return 'cien';
        }

        $centena = intdiv($n, 100);
        $resto = $n % 100;
        $partes = [];

        if ($centena > 0) {
            $partes[] = self::CENTENAS[$centena];
        }

        if ($resto > 0 && $resto < 30) {
            $partes[] = self::UNIDADES[$resto];
        } elseif ($resto >= 30) {
            $decena = intdiv($resto, 10);
            $unidad = $resto % 10;

            $partes[] = $unidad === 0
                ? self::DECENAS[$decena]
                : self::DECENAS[$decena].' y '.self::UNIDADES[$unidad];
        }

        return implode(' ', $partes);
    }

    private static function hasta999999(int $n): string
    {
        $miles = intdiv($n, 1000);
        $unidades = $n % 1000;
        $partes = [];

        if ($miles === 1) {
            // «mil», no «un mil».
            $partes[] = 'mil';
        } elseif ($miles > 0) {
            $partes[] = self::apocopar(self::hasta999($miles)).' mil';
        }

        if ($unidades > 0) {
            $partes[] = self::hasta999($unidades);
        }

        return implode(' ', $partes);
    }

    private static function entero(int $n): string
    {
        if ($n === 0) {
            return 'cero';
        }

        $millones = intdiv($n, 1_000_000);
        $resto = $n % 1_000_000;
        $partes = [];

        if ($millones === 1) {
            $partes[] = 'un millón';
        } elseif ($millones > 0) {
            $partes[] = self::apocopar(self::hasta999999($millones)).' millones';
        }

        if ($resto > 0) {
            $partes[] = self::hasta999999($resto);
        }

        return implode(' ', $partes);
    }
}
