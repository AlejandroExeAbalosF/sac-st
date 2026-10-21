<?php

declare(strict_types=1);

namespace App\Modules\Multas\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** El recurso por el que se paga el 20 % de la multa (M07). */
#[TypeScript]
enum AppealKind: string
{
    case Hierarchical = 'hierarchical';
    case Appeal = 'appeal';

    public function label(): string
    {
        return match ($this) {
            self::Hierarchical => 'Recurso jerárquico',
            self::Appeal => 'Apelación',
        };
    }
}
