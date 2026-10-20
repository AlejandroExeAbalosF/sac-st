<?php

declare(strict_types=1);

namespace App\Modules\Multas\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un movimiento de la obligación.
 *
 * La constitución es el importe original. Los ajustes lo suben o lo bajan
 * con su resolución, y una reversión deshace un ajuste entero.
 */
#[TypeScript]
enum FineDebtEntryKind: string
{
    case Constitution = 'constitution';
    case Increase = 'increase';
    case Reduction = 'reduction';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Constitution => 'Importe original',
            self::Increase => 'Aumento',
            self::Reduction => 'Reducción',
            self::Reversal => 'Reversión',
        };
    }
}
