<?php

declare(strict_types=1);

namespace App\Modules\Multas\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * El tipo de acta: lo que distingue la multa laboral de la de higiene y
 * seguridad. No es una categoría de la multa (DER físico v1.1, §10): vive en
 * el acta, que es donde el papel lo dice.
 */
#[TypeScript]
enum FineActType: string
{
    case Labor = 'LAB';
    case HealthSafety = 'HYS';

    public function label(): string
    {
        return match ($this) {
            self::Labor => 'Laboral',
            self::HealthSafety => 'Higiene y seguridad',
        };
    }
}
