<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Enums;

/**
 * Estado operativo de una cuota.
 *
 * `Paid` no significa «se pagó y listo»: significa que el egreso al
 * beneficiario está confirmado. Que la cuota esté financiada —que haya
 * entrado el dinero— es otra cosa, y se deriva de las asignaciones.
 */
enum InstallmentWorkflowStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Blocked = 'blocked';
    case Cancelled = 'cancelled';
    case Paid = 'paid';

    /**
     * Pagada **fuera del circuito**: en papel antes de la apertura, o desde
     * «Pagos anteriores». Cómo, lo dice su `LegacySettlement`; el estado
     * solo dice que a esta cuota ya no se le paga nada más.
     */
    case LegacySettled = 'legacy_settled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Pendiente',
            self::Suspended => 'Suspendida',
            self::Blocked => 'Bloqueada',
            self::Cancelled => 'Anulada',
            self::Paid => 'Pagada',
            self::LegacySettled => 'Pagada fuera del circuito',
        };
    }
}
