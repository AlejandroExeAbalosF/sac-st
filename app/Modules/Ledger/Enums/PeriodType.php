<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Qué tramo cubre un cierre — §9.9 del DER.
 *
 * El área confirmó que **las cajas se cierran todos los días**; el mensual
 * existe porque el DER lo pide y porque la planilla de junio es un libro
 * por mes. Los dos conviven sin duplicar nada: los movimientos pertenecen
 * a un período **por su fecha operativa**, no por un puntero guardado en
 * cada uno, así que la misma operación integra el cierre diario y el
 * mensual sin contarse dos veces.
 */
enum PeriodType: string
{
    case Daily = 'daily';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Diario',
            self::Monthly => 'Mensual',
        };
    }

    /**
     * El primer día del período que contiene a la fecha dada.
     *
     * Se llama `startsOn` y no `from` porque `from` es el constructor
     * nativo de todo enum respaldado: declararlo acá lo pisaría y las
     * llamadas terminarían resolviendo al método equivocado.
     */
    public function startsOn(CarbonInterface $date): CarbonImmutable
    {
        $fecha = CarbonImmutable::parse($date)->startOfDay();

        return match ($this) {
            self::Daily => $fecha,
            self::Monthly => $fecha->startOfMonth(),
        };
    }

    /** El último. Para el diario coincide con el primero, y el `CHECK` lo exige. */
    public function endsOn(CarbonInterface $date): CarbonImmutable
    {
        $fecha = CarbonImmutable::parse($date)->startOfDay();

        return match ($this) {
            self::Daily => $fecha,
            self::Monthly => $fecha->endOfMonth()->startOfDay(),
        };
    }
}
