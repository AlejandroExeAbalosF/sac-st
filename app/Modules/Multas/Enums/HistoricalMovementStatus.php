<?php

declare(strict_types=1);

namespace App\Modules\Multas\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Un antecedente cuenta en el saldo recién cuando alguien lo valida. */
#[TypeScript]
enum HistoricalMovementStatus: string
{
    case PendingReview = 'pending_review';
    case Validated = 'validated';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pendiente de revisión',
            self::Validated => 'Validado',
            self::Rejected => 'Descartado',
        };
    }
}
