<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Cómo se calcula el importe de un renglón de este tipo de arancel. */
#[TypeScript]
enum FeeCalculationMode: string
{
    /** Cantidad por precio: fijo, por folio o por notificación. */
    case Unit = 'unit';

    /** Un porcentaje sobre un monto base. */
    case Percentage = 'percentage';
}
