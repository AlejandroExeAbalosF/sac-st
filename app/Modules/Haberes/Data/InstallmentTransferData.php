<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\BankTransactionAllocation;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Shared\Models\AuditEvent;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * El traslado del efectivo de una cuota al banco, visto desde su tarjeta.
 *
 * Lleva el estado porque es lo que decide qué ofrecer: en tránsito, buscar
 * la acreditación; acreditado, nada más que mirarlo.
 */
#[TypeScript]
final class InstallmentTransferData extends Data
{
    public function __construct(
        public int $id,
        /** @var numeric-string */
        public string $amount,
        public string $depositDate,
        public CashTransferStatus $status,
        public ?string $operationNumber,
        /** El movimiento del extracto que lo confirmó, si ya acreditó. */
        public ?int $bankTransactionId,
        public ?string $creditedDate,
        /*
         * De la baja. La tabla no las guarda —el traslado solo pasa a
         * `cancelled` con su motivo en `notes`— así que quién y cuándo
         * salen del evento de auditoría, que es donde viven.
         */
        public ?string $cancelledAt = null,
        public ?string $cancelledByName = null,
        public ?string $cancelReason = null,
    ) {}

    public static function fromModel(CashToBankTransfer $transfer, ?AuditEvent $baja = null): self
    {
        $imputacion = $transfer->credit_event_id === null
            ? null
            : BankTransactionAllocation::query()
                ->where('financial_event_id', $transfer->credit_event_id)
                ->first();

        return new self(
            id: $transfer->id,
            amount: $transfer->amount,
            depositDate: $transfer->deposit_date->format('Y-m-d'),
            status: $transfer->status,
            operationNumber: $transfer->deposit_operation_number,
            bankTransactionId: $imputacion?->bank_transaction_id,
            creditedDate: $imputacion?->allocated_at?->format('Y-m-d'),
            cancelledAt: $baja?->occurred_at->toIso8601String(),
            cancelledByName: $baja?->user?->name,
            cancelReason: $transfer->notes,
        );
    }
}
