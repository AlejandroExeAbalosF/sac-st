<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum FeeChargeStatus: string
{
    /** Creado en ventanilla: espera el depósito. */
    case Pending = 'pending';

    /** Con su dinero y su recibo. */
    case Paid = 'paid';

    /** Anulado o corregido por otro cobro: queda a la vista. */
    case Voided = 'voided';
}
