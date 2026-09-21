<?php

declare(strict_types=1);

namespace App\Support;

/**
 * La contraseña de un solo uso que recibe un usuario recién dado de alta.
 *
 * No usa `Str::password()` por una razón concreta: esta clave se dicta por
 * teléfono o se copia de una pantalla a otra, así que quedan afuera los
 * caracteres que se confunden al leerlos —`0` y `O`, `1`, `l` y `I`— y los
 * símbolos que dependen de la distribución del teclado.
 *
 * Cumple la política de `Password::defaults()` por construcción, no por
 * suerte: se toma una cantidad fija de cada clase y recién después se
 * mezcla. Generarla al azar y confiar en que salga con mayúscula dejaría
 * un alta que falla una vez cada tantas.
 */
final class TemporaryPassword
{
    private const LOWER = 'abcdefghijkmnpqrstuvwxyz';

    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const DIGITS = '23456789';

    /** Solo los que están en el mismo lugar en cualquier teclado del área. */
    private const SYMBOLS = '.-_#$%*+=';

    /** Dieciséis caracteres, repartidos para cumplir la política de arranque. */
    public static function generate(): string
    {
        $characters = [
            ...self::pick(self::LOWER, 5),
            ...self::pick(self::UPPER, 4),
            ...self::pick(self::DIGITS, 4),
            ...self::pick(self::SYMBOLS, 3),
        ];

        // Fisher-Yates con `random_int` y no `str_shuffle`, que usa el
        // generador rápido. La entropía ya la puso la elección de cada
        // carácter; lo que se evita acá es que las minúsculas queden
        // siempre al principio, delatando la forma de la contraseña.
        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }

    /**
     * @return list<string>
     */
    private static function pick(string $alphabet, int $count): array
    {
        $picked = [];

        for ($i = 0; $i < $count; $i++) {
            $picked[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $picked;
    }
}
