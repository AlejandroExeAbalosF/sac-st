<?php

declare(strict_types=1);

namespace App\Modules\Multas\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Si el comprobante se presentó antes o después del cierre del mes en que
 * se registró la multa (M08). Se calcula una vez, al aplicar el pago.
 */
#[TypeScript]
enum FineTimeliness: string
{
    case OnTime = 'on_time';
    case Late = 'late';
    case Undetermined = 'undetermined';

    public function label(): string
    {
        return match ($this) {
            self::OnTime => 'En término',
            self::Late => 'Fuera de término',
            self::Undetermined => 'Término a revisar',
        };
    }
}
