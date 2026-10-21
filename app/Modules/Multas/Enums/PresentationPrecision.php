<?php

declare(strict_types=1);

namespace App\Modules\Multas\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Cuánto se sabe de cuándo se presentó un comprobante.
 *
 * El cobro en la caja tiene el instante; un sello de Mesa de Entradas da el
 * día, no la hora.
 */
#[TypeScript]
enum PresentationPrecision: string
{
    case Instant = 'instant';
    case Day = 'day';
    case Unknown = 'unknown';
}
