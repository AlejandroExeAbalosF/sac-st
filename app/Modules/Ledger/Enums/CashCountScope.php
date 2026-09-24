<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

/**
 * De qué conteo es una línea del arqueo.
 *
 * El cajón guarda dos cosas distintas: lo que entró hoy y el fajo que viene
 * arrastrándose de días anteriores. Contar el primero es la rutina de todas
 * las tardes; abrir el segundo es un acto excepcional.
 *
 * Separarlas es lo que permite que cada una conserve su composición. Fundidas
 * en un solo conteo, un faltante del fondo histórico se lee como un faltante
 * de la recaudación del día, que es otra cosa y tiene otro culpable.
 */
enum CashCountScope: string
{
    /** El movimiento de la jornada. */
    case Day = 'day';

    /** El fajo de días anteriores, cuando se lo abre y se lo cuenta. */
    case Carry = 'carry';

    public function label(): string
    {
        return match ($this) {
            self::Day => 'Conteo del día',
            self::Carry => 'Recuento del fajo',
        };
    }
}
