<?php

declare(strict_types=1);

namespace App\Modules\Multas\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Dónde está un pago.
 *
 * Solo el aplicado toca la deuda y lleva recibo. Uno informado espera que
 * se verifique el crédito; uno descartado no correspondía o se corrigió.
 */
#[TypeScript]
enum FinePaymentStatus: string
{
    case Reported = 'reported';
    case Applied = 'applied';
    case Discarded = 'discarded';

    public function label(): string
    {
        return match ($this) {
            self::Reported => 'Informado',
            self::Applied => 'Aplicado',
            self::Discarded => 'Descartado',
        };
    }
}
