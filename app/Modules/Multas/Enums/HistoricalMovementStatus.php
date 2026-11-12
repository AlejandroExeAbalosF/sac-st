<?php

declare(strict_types=1);

namespace App\Modules\Multas\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un antecedente cargado a mano cuenta en el saldo recién cuando alguien lo
 * valida. Uno importado de la planilla entra provisional: cuenta desde el
 * primer día y se revisa cuando se pueda.
 */
#[TypeScript]
enum HistoricalMovementStatus: string
{
    case PendingReview = 'pending_review';
    case Provisional = 'provisional';
    case Validated = 'validated';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pendiente de revisión',
            self::Provisional => 'Importado, sin revisar',
            self::Validated => 'Validado',
            self::Rejected => 'Descartado',
        };
    }

    /** Si suma al saldo de la multa. */
    public function counts(): bool
    {
        return $this === self::Validated || $this === self::Provisional;
    }

    /** Si todavía se puede validar o descartar. */
    public function isReviewable(): bool
    {
        return $this === self::PendingReview || $this === self::Provisional;
    }
}
