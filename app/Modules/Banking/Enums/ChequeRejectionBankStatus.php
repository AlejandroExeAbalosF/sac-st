<?php

declare(strict_types=1);

namespace App\Modules\Banking\Enums;

/**
 * Qué se sabe del banco sobre un cheque rechazado (DER físico de Multas §6.6).
 *
 * Es un seguimiento aparte de la regularización: la empresa puede reemplazar
 * el cheque antes o después de que el débito llegue al extracto. Cada paso es
 * un hecho real con su asiento; ninguno se supone.
 */
enum ChequeRejectionBankStatus: string
{
    /** El cheque no salió de la caja, o se confirmó que el banco nunca lo acreditó. */
    case NotApplicable = 'not_applicable';

    /**
     * Se depositó y el sistema no tenía registrada la acreditación: el
     * extracto todavía no dijo si la hubo. No conciliado no es nunca acreditado.
     */
    case Unverified = 'unverified';

    /** El banco acreditó y avisó el rechazo; el débito no está en el extracto. */
    case DebitPending = 'debit_pending';

    /** El débito está en el extracto y se imputó. */
    case DebitRecorded = 'debit_recorded';

    public function label(): string
    {
        return match ($this) {
            self::NotApplicable => 'No corresponde',
            self::Unverified => 'Revisión bancaria pendiente',
            self::DebitPending => 'Rechazo con acreditación previa: revisión bancaria pendiente',
            self::DebitRecorded => 'Débito registrado',
        };
    }

    /** Si todavía falta un hecho del banco. */
    public function isOpen(): bool
    {
        return $this === self::Unverified || $this === self::DebitPending;
    }
}
