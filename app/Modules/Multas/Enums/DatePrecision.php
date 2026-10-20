<?php

declare(strict_types=1);

namespace App\Modules\Multas\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Cuánto se sabe de una fecha.
 *
 * El Excel histórico da meses, no días: un pago de «julio de 2025» se
 * guarda como el 1 de julio con precisión `month`, sin inventar el día.
 */
#[TypeScript]
enum DatePrecision: string
{
    case Day = 'day';
    case Month = 'month';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Day => 'Día exacto',
            self::Month => 'Solo el mes',
            self::Unknown => 'Desconocida',
        };
    }
}
