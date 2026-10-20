<?php

declare(strict_types=1);

namespace App\Modules\Multas\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Un antecedente: lo que se pagó, o se devolvió, sin pasar por el libro. */
#[TypeScript]
enum HistoricalMovementKind: string
{
    case Payment = 'payment';
    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::Payment => 'Pago anterior',
            self::Refund => 'Devolución anterior',
        };
    }
}
