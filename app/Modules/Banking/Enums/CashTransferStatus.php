<?php

declare(strict_types=1);

namespace App\Modules\Banking\Enums;

/**
 * Dónde está el efectivo que salió de la caja.
 *
 * Los tres estados son tres lugares distintos del dinero, no tres etapas
 * de un trámite: `deposited` es «salió de la caja y el banco todavía no lo
 * confirmó», que es precisamente la ventana que justifica la cuenta
 * `CASH_IN_TRANSIT`.
 */
enum CashTransferStatus: string
{
    /** El efectivo salió de la caja. En tránsito hasta que el banco acredite. */
    case Deposited = 'deposited';

    /** El extracto confirmó el crédito. La ventana se cerró. */
    case BankConfirmed = 'bank_confirmed';

    /**
     * El traslado se dio de baja y su efectivo vuelve a estar disponible.
     *
     * Todavía no lo produce nadie: cancelar un traslado exige revertir su
     * asiento, y las reversiones son de la tanda siguiente. El estado se
     * declara ahora porque vive en un `CHECK` de una tabla append-only.
     */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Deposited => 'En tránsito',
            self::BankConfirmed => 'Acreditado',
            self::Cancelled => 'Cancelado',
        };
    }

    /** Si sigue esperando que el extracto lo confirme. */
    public function awaitsCredit(): bool
    {
        return $this === self::Deposited;
    }
}
