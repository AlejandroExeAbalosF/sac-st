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
}
