<?php

declare(strict_types=1);

namespace App\Modules\Multas\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Dónde está el trámite del recurso (decisión del área, 03/11/2026).
 *
 * En trámite congela la multa; prosperó la cancela con lo cobrado; no
 * prosperó la descongela con lo pagado a cuenta. Cayó cuando su depósito
 * informado se descartó antes del veredicto: la multa admite otro.
 */
#[TypeScript]
enum FineAppealStatus: string
{
    case Open = 'open';
    case Upheld = 'upheld';
    case Rejected = 'rejected';
    case Dropped = 'dropped';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'En trámite',
            self::Upheld => 'Prosperó',
            self::Rejected => 'No prosperó',
            self::Dropped => 'Cayó',
        };
    }

    /** Los dos veredictos: lo demás no se elige al resolver. */
    public function isVerdict(): bool
    {
        return $this === self::Upheld || $this === self::Rejected;
    }
}
