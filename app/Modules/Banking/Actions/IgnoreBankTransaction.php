<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Shared\Actions\RecordAuditEvent;
use RuntimeException;

/**
 * Declara que un movimiento no entra en la contabilidad del circuito.
 *
 * Es para lo que nunca va a tener un evento financiero detrás: la comisión
 * de $121 del causal 3914, la transferencia entre dos cuentas del propio
 * organismo del 3861. Sin esta salida esos movimientos quedarían para
 * siempre en la cola de pendientes y la volverían inútil.
 *
 * **No borra ni oculta nada.** El movimiento sigue existiendo, sigue
 * sumando en el saldo y sigue siendo conciliable; lo único que cambia es
 * que deja de reclamar atención. Por eso exige motivo y responsable: es
 * una decisión de alguien, no un estado que el sistema deduce.
 */
final class IgnoreBankTransaction
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    public function handle(BankTransaction $transaction, string $reason, int $userId): BankTransaction
    {
        if ($transaction->reconciliation_status === ReconciliationStatus::Ignored) {
            throw new RuntimeException('El movimiento ya estaba marcado como fuera del circuito.');
        }

        if ($transaction->reconciliation_status !== ReconciliationStatus::Pending) {
            throw new RuntimeException(
                'El movimiento ya tiene imputaciones contables: hay que revertirlas antes de dejarlo fuera del circuito.'
            );
        }

        $before = $transaction->reconciliation_status->value;

        $transaction->forceFill([
            'reconciliation_status' => ReconciliationStatus::Ignored,
            'ignored_reason' => mb_substr(trim($reason), 0, 300),
            'ignored_by' => $userId,
            'ignored_at' => now(),
        ])->save();

        $this->auditar->handle(
            'banco.movimiento.fuera-del-circuito',
            $transaction,
            before: ['reconciliation_status' => $before],
            after: [
                'reconciliation_status' => ReconciliationStatus::Ignored->value,
                'ignored_reason' => $transaction->ignored_reason,
            ],
            actorId: $userId,
        );

        return $transaction;
    }
}
