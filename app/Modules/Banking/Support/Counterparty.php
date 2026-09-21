<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

/**
 * Quién está del otro lado del movimiento, deducido del concepto.
 *
 * El banco no informa la contraparte en un campo propio: la mete dentro
 * del texto, y de media docena de formas distintas. Estas son las que
 * aparecen en el libro banco del área:
 *
 * ```
 * TRANSF SANDOVAL 20444444445 VAR VARIOS
 * CCERR J V L B AGR 30333333339 CIRC.CERRADO
 * TRANSF:3D5W612EJ6GK5EQG9GXYVR-30715030817
 * CREDIN:ORD6LEN87LXOLKJ42M1Y30-30710668929
 * ING TRANSF:HIPERMAYORISTA ATLAS S-30715030817
 * TEF DATANET PR HIPERMAYORISTA ATL 30715030817
 * 995979830 - Numero de Operacion
 * ```
 *
 * El CUIT es exacto y se verifica con su dígito verificador, así que sirve
 * para buscar al empleador en el maestro. El nombre es una heurística: el
 * banco lo trunca —«J V L B AGR» por «JVL B AGROPECUARIA»— y a veces no
 * está. Se guarda como referencia para el operador, nunca como identidad.
 *
 * La última línea del ejemplo es la que no trae nada: un depósito por
 * ventanilla. Esos son los que hoy caen en la columna
 * `DEPOSITO NO IDENTIFICADO` del libro banco, y van a seguir necesitando
 * que alguien decida de quién son.
 */
final class Counterparty
{
    /** Prefijos que el banco antepone y que no son parte del nombre. */
    private const PREFIXES = [
        'ING TRANSF', 'TEF DATANET PR', 'TEF DATANET', 'TRANSF', 'TRF MO CCDO',
        'TRF MO', 'CREDIN', 'CCERR', 'DEBIN', 'PAGO', 'TEF', 'TRF',
    ];

    /** Sufijos que describen el canal, no a la persona. */
    private const SUFFIXES = [
        'CIRC.CERRADO', 'CIRC CERRADO', 'VAR VARIOS', 'VARIOS', 'VAR',
        'DIST T', 'MISMO', 'LIQSU', 'HAB HABERES',
    ];

    public function __construct(
        public readonly ?string $name,
        public readonly ?string $identifier,
    ) {}

    public static function fromDescription(?string $description): self
    {
        if ($description === null || trim($description) === '') {
            return new self(null, null);
        }

        $text = preg_replace('/\s+/u', ' ', trim($description)) ?? '';
        $cuit = self::extractCuit($text);

        /*
         * Sin CUIT no se nombra a nadie.
         *
         * La tentación es devolver el texto que sobra —«Comision Trf.
         * MacrOL E-set», «995979830 - Numero de Operacion»— y dejar que el
         * operador decida. Pero ese texto ya está entero en `description`:
         * copiarlo acá no agrega un dato, agrega la impresión de que el
         * sistema identificó una contraparte cuando no identificó nada.
         *
         * Los depósitos por ventanilla van a seguir necesitando que
         * alguien decida de quién son, igual que hoy. Lo que no van a
         * hacer es aparecer con un nombre inventado.
         */
        if ($cuit === null) {
            return new self(null, null);
        }

        return new self(self::extractName($text, $cuit), $cuit);
    }

    /**
     * Primer número de once dígitos que además sea un CUIT válido.
     *
     * Verificar el dígito es lo que evita confundirlo con un número de
     * operación: `995979830 - Numero de Operacion` no pasa el filtro ni
     * por longitud ni por prefijo.
     */
    public static function extractCuit(string $text): ?string
    {
        if (preg_match_all('/(?<!\d)(\d{11})(?!\d)/', $text, $matches) === false) {
            return null;
        }

        foreach ($matches[1] as $candidate) {
            if (self::isValidCuit($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Módulo 11 sobre los diez primeros dígitos, con prefijo conocido. */
    public static function isValidCuit(string $cuit): bool
    {
        if (preg_match('/^\d{11}$/', $cuit) !== 1) {
            return false;
        }

        // 20/23/24/27 personas físicas, 30/33/34 personas jurídicas.
        if (! in_array(substr($cuit, 0, 2), ['20', '23', '24', '27', '30', '33', '34'], true)) {
            return false;
        }

        $weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;

        foreach ($weights as $position => $weight) {
            $sum += (int) $cuit[$position] * $weight;
        }

        $remainder = $sum % 11;
        $check = match ($remainder) {
            0 => 0,
            1 => 9,
            default => 11 - $remainder,
        };

        return $check === (int) $cuit[10];
    }

    /**
     * El texto que queda entre el prefijo del banco y el CUIT.
     *
     * Devuelve `null` antes que devolver basura: un identificador de
     * operación como `3D5W612EJ6GK5EQG9GXYVR` no es un nombre, y ofrecerlo
     * como tal solo confunde a quien tiene que decidir de quién es el
     * dinero.
     */
    private static function extractName(string $text, ?string $cuit): ?string
    {
        $candidate = $text;

        if ($cuit !== null) {
            $position = strpos($candidate, $cuit);

            if ($position !== false) {
                $candidate = substr($candidate, 0, $position);
            }
        }

        $candidate = trim($candidate, " \t-:");

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with(mb_strtoupper($candidate), $prefix)) {
                $candidate = trim(substr($candidate, strlen($prefix)), " \t-:");

                break;
            }
        }

        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with(mb_strtoupper($candidate), $suffix)) {
                $candidate = trim(substr($candidate, 0, -strlen($suffix)), " \t-:");

                break;
            }
        }

        $candidate = trim($candidate);

        if ($candidate === '' || mb_strlen($candidate) < 3) {
            return null;
        }

        // Un token sin espacios, largo y con letras y dígitos mezclados es
        // un identificador de operación, no el nombre de nadie.
        if (! str_contains($candidate, ' ')
            && mb_strlen($candidate) > 10
            && preg_match('/\d/', $candidate) === 1
            && preg_match('/[A-Za-z]/', $candidate) === 1
        ) {
            return null;
        }

        return mb_substr($candidate, 0, 200);
    }
}
