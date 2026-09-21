<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

/**
 * Valida los dos bloques y dígitos verificadores de un CBU argentino.
 *
 * La estructura es **una sola para todos los bancos**, definida por el
 * BCRA. Un banco nuevo no cambia la fórmula, así que no hay nada que
 * configurar por entidad:
 *
 * ```text
 * │ 285 │ 0000 │ 3 │ 0000000000001 │ 7 │
 * │ ent │ suc  │DV1│    cuenta     │DV2│
 * └──── bloque 1 (8) ───┴── bloque 2 (14) ──┘
 * ```
 *
 * Lo propio de cada banco son los tres primeros dígitos —el código de
 * entidad—, que sirven para *identificar* el banco, no para validar.
 */
final class Cbu
{
    /** @var list<int> */
    private const BANK_WEIGHTS = [7, 1, 3, 9, 7, 1, 3];

    /** @var list<int> */
    private const ACCOUNT_WEIGHTS = [3, 9, 7, 1, 3, 9, 7, 1, 3, 9, 7, 1, 3];

    /**
     * El código de entidad de las billeteras virtuales.
     *
     * Los proveedores de servicios de pago no son bancos y no tienen
     * número de entidad financiera, así que sus CVU se emiten bajo `000`.
     */
    private const VIRTUAL_WALLET_ENTITY = '000';

    public static function isValid(string $cbu): bool
    {
        if (strlen($cbu) !== 22 || ! ctype_digit($cbu)) {
            return false;
        }

        return self::checkDigit($cbu, 0, self::BANK_WEIGHTS) === (int) $cbu[7]
            && self::checkDigit($cbu, 8, self::ACCOUNT_WEIGHTS) === (int) $cbu[21];
    }

    /**
     * Si el número es un CVU de billetera virtual.
     *
     * **No se detecta por el dígito verificador.** Un CVU usa el mismo
     * formato de 22 dígitos que un CBU y pasa el mismo cálculo: lo que lo
     * distingue es la entidad emisora. Confundir las dos cosas lleva a
     * dar por buena una cuenta a la que el organismo no puede transferir.
     *
     * Es el caso que el área contó: el trabajador informó un CVU, y el
     * expediente volvió observado (§2.2.9).
     */
    public static function isVirtualWallet(string $cbu): bool
    {
        return strlen($cbu) === 22
            && ctype_digit($cbu)
            && str_starts_with($cbu, self::VIRTUAL_WALLET_ENTITY);
    }

    /** El código de entidad: los tres primeros dígitos. */
    public static function entityCode(string $cbu): ?string
    {
        return strlen($cbu) === 22 && ctype_digit($cbu) ? substr($cbu, 0, 3) : null;
    }

    /**
     * @param  list<int>  $weights
     */
    private static function checkDigit(string $cbu, int $offset, array $weights): int
    {
        $sum = 0;

        foreach ($weights as $index => $weight) {
            $sum += ((int) $cbu[$offset + $index]) * $weight;
        }

        return (10 - ($sum % 10)) % 10;
    }
}
