<?php

declare(strict_types=1);

namespace App\Support\Text;

/**
 * Nombre o apellido de una persona, escrito como se muestra: «Pérez», «de la Fuente».
 *
 * Solo corrige lo que llega todo en mayúsculas o todo en minúsculas —copiado
 * del DNI o tipeado de corrido—. Si quien cargó mezcló mayúsculas y minúsculas
 * a propósito («McKay», «DiMaría»), se respeta: una regla automática no puede
 * saber más que la persona que lo escribió.
 */
final class PersonName
{
    /** Van en minúscula salvo al principio: «Juan de la Cruz», «De la Fuente». */
    private const array PARTICLES = ['de', 'del', 'la', 'las', 'los', 'y'];

    public static function format(string $name): string
    {
        $clean = (string) preg_replace('/\s+/u', ' ', trim($name));

        if (mb_strtoupper($clean) !== $clean && mb_strtolower($clean) !== $clean) {
            return $clean;
        }

        $words = explode(' ', mb_strtolower($clean));

        foreach ($words as $index => $word) {
            $words[$index] = $index > 0 && in_array($word, self::PARTICLES, true)
                ? $word
                : self::capitalize($word);
        }

        return implode(' ', $words);
    }

    /** Cada tramo separado por guion o apóstrofo: «María-José», «D'Andrea». */
    private static function capitalize(string $word): string
    {
        return (string) preg_replace_callback(
            "/(^|[-'’])(\\p{L})/u",
            fn (array $match): string => $match[1].mb_strtoupper($match[2]),
            $word,
        );
    }
}
