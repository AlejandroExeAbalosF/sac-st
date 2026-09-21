<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Estado administrativo del expediente (DER §9.2). */
#[TypeScript]
enum ExpedienteStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activo',
            self::Suspended => 'Suspendido',
            self::Closed => 'Cerrado',
            self::Cancelled => 'Anulado',
        };
    }
}
