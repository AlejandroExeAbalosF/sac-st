<?php

declare(strict_types=1);

namespace App\Modules\Multas\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** De dónde salió el registro: cargado a mano o importado del Excel. */
#[TypeScript]
enum FineProvenance: string
{
    case Manual = 'manual';
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Carga manual',
            self::Import => 'Importado',
        };
    }
}
