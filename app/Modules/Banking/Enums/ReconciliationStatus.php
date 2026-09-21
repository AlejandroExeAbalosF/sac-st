<?php

declare(strict_types=1);

namespace App\Modules\Banking\Enums;

/**
 * Cuánto de un movimiento bancario está explicado contablemente.
 *
 * Es un marcador administrativo, no un hecho monetario: cambia con el
 * tiempo y cada cambio queda en `audit_events`. Por eso el trigger
 * append-only de `bank_transactions` lo deja pasar mientras bloquea el
 * importe y la fecha.
 *
 * `Ignored` es la salida para lo que nunca va a tener un evento
 * financiero detrás: la comisión de $121, la transferencia entre dos
 * cuentas del propio organismo. Exige motivo y responsable.
 */
enum ReconciliationStatus: string
{
    case Pending = 'pending';
    case Partial = 'partial';
    case Reconciled = 'reconciled';
    case Ignored = 'ignored';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Sin identificar',
            self::Partial => 'Identificado en parte',
            self::Reconciled => 'Identificado',
            self::Ignored => 'Fuera del circuito',
        };
    }
}
