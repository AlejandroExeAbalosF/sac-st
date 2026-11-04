<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum FeeChargeMedium: string
{
    case Cash = 'cash';
    case Bank = 'bank';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Efectivo',
            self::Bank => 'Depósito',
        };
    }
}
