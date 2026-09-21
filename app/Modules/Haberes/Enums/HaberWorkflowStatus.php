<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Estado de gestión del haber (DER §9.2).
 *
 * No confundir con el estado financiero de sus cuotas: un haber puede
 * estar `active` y tener cuotas sin financiar, financiadas o pagadas.
 * Este campo dice si el haber puede avanzar, no cuánto dinero entró.
 */
#[TypeScript]
enum HaberWorkflowStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Blocked = 'blocked';
    case Cancelled = 'cancelled';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activo',
            self::Suspended => 'Suspendido',
            self::Blocked => 'Bloqueado',
            self::Cancelled => 'Anulado',
            self::Closed => 'Cerrado',
        };
    }
}
