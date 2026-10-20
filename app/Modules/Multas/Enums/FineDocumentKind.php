<?php

declare(strict_types=1);

namespace App\Modules\Multas\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Qué papel es: la resolución que impone o modifica, el acta que la origina, u otro. */
#[TypeScript]
enum FineDocumentKind: string
{
    case Resolution = 'resolution';
    case Act = 'act';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Resolution => 'Resolución',
            self::Act => 'Acta',
            self::Other => 'Otro documento',
        };
    }
}
