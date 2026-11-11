<?php

declare(strict_types=1);

namespace App\Modules\Banking\Enums;

use App\Modules\Shared\Support\Reason;
use App\Modules\Shared\Support\ReasonAct;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Los actos de Banking que piden motivo de una lista.
 *
 * Reincorporar no tiene columna: el motivo queda en la auditoría, como el de
 * reactivar un haber.
 */
#[TypeScript]
enum BankingReasonAct: string implements ReasonAct
{
    /** Devolver a la conciliación un movimiento que se dejó fuera del circuito. */
    case Restore = 'restore';

    /**
     * @return list<BankingReasonCode>
     */
    public function codes(): array
    {
        return match ($this) {
            self::Restore => [BankingReasonCode::IgnoredByMistake, BankingReasonCode::BelongsToPayment, BankingReasonCode::Other],
        };
    }

    /**
     * @return array<string, list<array{value: string|int, label: string}>>
     */
    public static function options(): array
    {
        return Reason::options(self::class);
    }
}
