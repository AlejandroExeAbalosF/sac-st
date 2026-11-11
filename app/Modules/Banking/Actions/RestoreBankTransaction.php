<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Support\Reason;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Devuelve a la conciliación un movimiento que se dejó fuera del circuito.
 *
 * Es la vuelta de `IgnoreBankTransaction`, para cuando esa decisión fue un
 * error: la comisión de una transferencia que se excluyó y resulta ser
 * parte de un pago —la absorbe el beneficiario y se imputa con el egreso—,
 * o un débito que sí pertenecía a una caja.
 *
 * **Exige motivo y deja rastro**, igual que excluir. El motivo excluido
 * desaparece de la fila —la base exige que un movimiento pendiente no lo
 * tenga—, pero queda en la auditoría junto con quién lo reincorporó y por
 * qué. Las dos decisiones se leen una detrás de otra en el historial.
 *
 * Vuelve a `pending` y no a otro estado: excluir solo se permite sin
 * imputaciones, así que no hay nada que recalcular.
 */
final class RestoreBankTransaction
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    public function handle(BankTransaction $transaction, Reason $reason, int $userId): BankTransaction
    {
        return DB::transaction(function () use ($transaction, $reason, $userId): BankTransaction {
            /** @var BankTransaction $movimiento */
            $movimiento = BankTransaction::query()->lockForUpdate()->findOrFail($transaction->id);

            if ($movimiento->reconciliation_status !== ReconciliationStatus::Ignored) {
                throw new RuntimeException('El movimiento no está fuera del circuito.');
            }

            $antes = [
                'reconciliation_status' => $movimiento->reconciliation_status->value,
                'ignored_reason' => $movimiento->ignored_reason,
                'ignored_by' => $movimiento->ignored_by,
                'ignored_at' => $movimiento->ignored_at?->toIso8601String(),
            ];

            $movimiento->forceFill([
                'reconciliation_status' => ReconciliationStatus::Pending,
                'ignored_reason' => null,
                'ignored_by' => null,
                'ignored_at' => null,
            ])->save();

            $this->auditar->handle(
                'banco.movimiento.reincorporado',
                $movimiento,
                before: $antes,
                after: ['reconciliation_status' => ReconciliationStatus::Pending->value],
                metadata: $reason->auditMetadata(),
                actorId: $userId,
            );

            return $movimiento;
        });
    }
}
