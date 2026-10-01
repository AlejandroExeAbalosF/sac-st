<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Cómo se pagó una cuota fuera del circuito.
 *
 * Las dos dejan la cuota saldada, pero no dicen lo mismo: la primera no
 * pasó nunca por el sistema; la segunda sí, como un egreso de «Pagos
 * anteriores» que el libro ya tiene.
 */
#[TypeScript]
enum LegacySettlementMode: string
{
    /** En papel, antes de que el sistema abriera los libros. */
    case BeforeOpening = 'before_opening';

    /** Desde «Pagos anteriores», con su recibo de egreso del sistema. */
    case LegacyDisbursement = 'legacy_disbursement';

    public function label(): string
    {
        return match ($this) {
            self::BeforeOpening => 'Pagada antes de la apertura',
            self::LegacyDisbursement => 'Pagada desde Pagos anteriores',
        };
    }
}
