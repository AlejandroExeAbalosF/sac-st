<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Support;

use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Dos personas guardando el mismo código o inciso a la vez: la validación de
 * ninguna lo vio y la base frenó a la segunda. Se le explica en el campo.
 */
final class FeeTypeUniqueness
{
    /** @return array<string, string> */
    public static function messagesFor(UniqueConstraintViolationException $e): array
    {
        return str_contains($e->getMessage(), 'fee_types_decree_letter_unique')
            ? ['decreeLetter' => 'Ya hay un tipo de arancel con ese inciso.']
            : ['code' => 'Ya hay un tipo de arancel con ese código.'];
    }
}
