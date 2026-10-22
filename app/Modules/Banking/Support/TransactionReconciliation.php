<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Models\BankTransaction;
use App\Support\Money\Decimal;

/**
 * El estado de conciliación de un movimiento, a partir de lo que ya se le
 * imputó.
 *
 * Se recalcula en vez de acumularse: sin imputar, pendiente; imputado
 * entero, conciliado; en parte, parcial. Lo usan todas las imputaciones del
 * extracto —recepciones, acreditaciones, el crédito y el débito de un
 * cheque rechazado—.
 */
final class TransactionReconciliation
{
    public function __construct(private readonly AllocatableAmount $disponible) {}

    public function refresh(BankTransaction $transaction): void
    {
        $imputado = $this->disponible->allocated($transaction);
        $total = Decimal::abs($transaction->amount);

        $estado = match (true) {
            Decimal::equals($imputado, '0') => ReconciliationStatus::Pending,
            Decimal::equals($imputado, $total) => ReconciliationStatus::Reconciled,
            default => ReconciliationStatus::Partial,
        };

        $transaction->forceFill(['reconciliation_status' => $estado])->save();
    }
}
