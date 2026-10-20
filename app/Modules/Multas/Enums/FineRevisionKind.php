<?php

declare(strict_types=1);

namespace App\Modules\Multas\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Completar es llenar un vacío o precisar una fecha; corregir es cambiar un
 * dato que ya tenía valor, y lleva motivo.
 */
#[TypeScript]
enum FineRevisionKind: string
{
    case Completion = 'completion';
    case Correction = 'correction';

    public function label(): string
    {
        return match ($this) {
            self::Completion => 'Completado',
            self::Correction => 'Corrección',
        };
    }
}
