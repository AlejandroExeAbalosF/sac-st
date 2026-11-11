<?php

declare(strict_types=1);

namespace App\Modules\Banking\Enums;

use App\Modules\Shared\Support\ReasonCode;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * El motivo de un acto de Banking, elegido de una lista para poder contarlo.
 * La lista no sale del relevamiento: es un punto de partida para ajustar con
 * el área.
 */
#[TypeScript]
enum BankingReasonCode: string implements ReasonCode
{
    case IgnoredByMistake = 'ignored_by_mistake';
    case BelongsToPayment = 'belongs_to_payment';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::IgnoredByMistake => 'Se excluyó por error',
            self::BelongsToPayment => 'Corresponde a un pago del circuito',
            self::Other => 'Otro',
        };
    }
}
