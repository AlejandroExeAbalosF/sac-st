<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Enums;

/**
 * En qué punto está el ticket.
 *
 * `Waiting` es el estado que hoy vive en la cabeza de la contadora: el
 * expediente trajo el comprobante y falta que el crédito aparezca en el
 * extracto. Es la cola de trabajo del módulo.
 */
enum DepositTicketStatus: string
{
    /** Cargado, esperando que el movimiento aparezca en el banco. */
    case Waiting = 'waiting';

    /** Encontrado en el extracto y vinculado a su movimiento. */
    case Matched = 'matched';

    /**
     * No va a aparecer: el ticket estaba mal, el depósito se anuló, o se
     * cargó por error. Exige motivo.
     */
    case Discarded = 'discarded';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Esperando en el banco',
            self::Matched => 'Encontrado',
            self::Discarded => 'Descartado',
        };
    }
}
