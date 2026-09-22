<?php

declare(strict_types=1);

namespace App\Support;

use BackedEnum;
use LogicException;

/**
 * El mapa valor → rótulo de un enum que sabe nombrarse.
 *
 * Existe para no reescribir en otro lado lo que el enum ya dice: la
 * auditoría traduce estados guardados como texto, y si el rótulo de un
 * estado cambia tiene que cambiar en todas las pantallas a la vez.
 */
final class EnumLabels
{
    /**
     * @param  class-string<BackedEnum>  $enum
     * @return array<string, string>
     */
    public static function of(string $enum): array
    {
        $labels = [];

        foreach ($enum::cases() as $case) {
            if (! method_exists($case, 'label')) {
                throw new LogicException("{$enum} no sabe nombrar sus casos: le falta label().");
            }

            $labels[(string) $case->value] = (string) $case->label();
        }

        return $labels;
    }
}
