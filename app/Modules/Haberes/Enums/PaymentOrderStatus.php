<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Enums;

/**
 * Dónde está una Orden de Pago — docs/haberes-reglas.md#orden-y-pase.
 *
 * Los siete primeros son etapas del documento en circulación; los tres
 * últimos son finales. La diferencia no es cosmética: mientras la Orden
 * está en circulación **ocupa el lugar de su cuota**, y no puede haber
 * otra. Lo impone un índice único parcial que enumera exactamente los
 * mismos estados que `isActive()`.
 *
 * La emisión produce `Draft`, la anulación `Voided` y la validación del
 * egreso `Completed`. Los estados intermedios de la transferencia se
 * resuelven en el egreso con `TransferStage`; declararlos también aquí
 * no implica que cada uno tenga una transición de Orden implementada.
 */
enum PaymentOrderStatus: string
{
    /** Emitida y numerada. Todavía no salió del área. */
    case Draft = 'draft';

    case Reviewed = 'reviewed';
    case Approved = 'approved';

    /** Remitida al organismo junto con su Pase. */
    case Sent = 'sent';

    case TransferReported = 'transfer_reported';
    case BankDebitObserved = 'bank_debit_observed';
    case ReadyForValidation = 'ready_for_validation';

    /** El egreso se validó y la cuota quedó pagada. */
    case Completed = 'completed';

    /** El organismo la rechazó. */
    case Rejected = 'rejected';

    /** El área la dio de baja. Su número queda consumido. */
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Emitida',
            self::Reviewed => 'Revisada',
            self::Approved => 'Aprobada',
            self::Sent => 'Remitida al organismo',
            self::TransferReported => 'Transferencia informada',
            self::BankDebitObserved => 'Débito observado',
            self::ReadyForValidation => 'Lista para validar',
            self::Completed => 'Completada',
            self::Rejected => 'Rechazada',
            self::Voided => 'Anulada',
        };
    }

    /**
     * Si ocupa el lugar de su cuota.
     *
     * Espeja el índice `payment_orders_one_active_per_installment`. Si
     * alguna vez dejaran de coincidir, la pantalla ofrecería emitir una
     * Orden que la base va a rechazar; por eso la lista está una sola vez
     * en cada lado y las dos se leen juntas.
     */
    public function isActive(): bool
    {
        return ! in_array($this, [self::Completed, self::Rejected, self::Voided], true);
    }

    /** Si el documento ya salió del área y está en manos del organismo. */
    public function isCirculating(): bool
    {
        return ! in_array($this, [self::Draft, self::Reviewed, self::Approved], true);
    }
}
