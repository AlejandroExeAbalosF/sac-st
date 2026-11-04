<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Modules\Banking\Enums\BankAllocationRole;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\BankTransactionAllocation;
use App\Modules\Banking\Support\TransactionReconciliation;
use App\Modules\Ledger\Models\FundReceipt;

/**
 * Una recepción revertida devuelve el saldo libre a su movimiento del extracto.
 *
 * Es lo que permite volver a imputarlo bien: sin esto, la reversión limpiaría
 * los libros y dejaría el crédito bancario trabado para siempre.
 *
 * La imputación no se borra —nada del libro se borra—: se le agrega su reversa,
 * que es lo que `AllocatableAmount` netea. Y el estado de conciliación se
 * recalcula, porque un movimiento que vuelve a estar sin imputar tiene que
 * volver a ofrecerse.
 *
 * Se llama dentro de la transacción que revierte la recepción.
 */
final class ReleaseFundReceiptAllocations
{
    public function __construct(private readonly TransactionReconciliation $conciliacion) {}

    public function handle(FundReceipt $recepcion, int $eventoId, string $reason, ?int $actorId): void
    {
        $imputaciones = BankTransactionAllocation::query()
            ->where('financial_event_id', $recepcion->financial_event_id)
            ->whereNull('reversal_of_id')
            ->get();

        foreach ($imputaciones as $imputacion) {
            BankTransactionAllocation::query()->create([
                'bank_transaction_id' => $imputacion->bank_transaction_id,
                'financial_event_id' => $eventoId,
                'allocation_role' => BankAllocationRole::FundsReceived,
                'reversal_of_id' => $imputacion->id,
                'amount' => $imputacion->amount,
                'allocated_by' => $actorId,
                'allocated_at' => now(),
                'notes' => $reason,
            ]);
        }

        $movimientos = BankTransaction::query()
            ->whereIn('id', $imputaciones->pluck('bank_transaction_id')->unique())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($movimientos as $movimiento) {
            $this->conciliacion->refresh($movimiento);
        }
    }
}
