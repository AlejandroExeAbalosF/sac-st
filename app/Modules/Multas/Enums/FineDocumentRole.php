<?php

declare(strict_types=1);

namespace App\Modules\Multas\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Qué papel cumple un documento en una multa. */
#[TypeScript]
enum FineDocumentRole: string
{
    case Origin = 'origin';
    case Modification = 'modification';
    case Ratification = 'ratification';
    case Support = 'support';

    public function label(): string
    {
        return match ($this) {
            self::Origin => 'Origen',
            self::Modification => 'Modificación',
            self::Ratification => 'Ratificación',
            self::Support => 'Respaldo',
        };
    }
}
