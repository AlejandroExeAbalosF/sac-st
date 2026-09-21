<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Enums\ResidualStatus;
use App\Modules\Ledger\Models\FundReceipt;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * La recepción con todo lo que hace falta para asignarla.
 *
 * Trae también de dónde vino —el movimiento bancario y, si el ticket llegó
 * a vincularse, el expediente— porque es lo que le permite a la pantalla
 * proponer las cuotas correctas sin que el operador tenga que buscarlas.
 */
#[TypeScript]
final class FundReceiptDetailData extends Data
{
    public function __construct(
        public int $id,
        public string $publicId,
        public string $receivedDate,
        public PaymentMedium $medium,
        /** @var numeric-string */
        public string $amount,
        /** @var numeric-string */
        public string $allocated,
        /** @var numeric-string */
        public string $unallocated,
        public ResidualStatus $residualStatus,
        public ?string $residualNote,
        public ?string $depositorName,
        public ?string $cashBoxName,
        public ?string $receivedByName,
        public ?string $notes,
        /*
         * De la baja, cuando la hay. Quién y cuándo salen de la fila:
         * revertir es un hecho de la recepción, no de otro modelo.
         */
        public ?string $reversedAt,
        public ?string $reversedByName,
        public ?string $reversalReason,
        /** El crédito del extracto que la respalda. */
        public ?int $bankTransactionId,
        public ?string $bankTransactionDescription,
        public ?string $bankAccountLabel,
        /** El expediente del ticket, cuando el cruce ya se hizo. */
        public ?int $expedienteId,
        public ?string $expedienteNumber,
        public ?string $employerName,
    ) {}

    /**
     * @param  numeric-string  $allocated
     * @param  numeric-string  $unallocated
     */
    public static function fromModel(
        FundReceipt $receipt,
        string $allocated,
        string $unallocated,
        ?int $bankTransactionId = null,
        ?string $bankTransactionDescription = null,
        ?string $bankAccountLabel = null,
        ?int $expedienteId = null,
        ?string $expedienteNumber = null,
        ?string $employerName = null,
    ): self {
        return new self(
            id: $receipt->id,
            publicId: $receipt->financialEvent->public_id,
            receivedDate: $receipt->received_date->format('Y-m-d'),
            medium: $receipt->medium,
            amount: $receipt->amount,
            allocated: $allocated,
            unallocated: $unallocated,
            residualStatus: $receipt->residual_status,
            residualNote: $receipt->residual_note,
            depositorName: $receipt->depositor?->name,
            cashBoxName: $receipt->cashBox?->name,
            receivedByName: $receipt->receivedBy?->name,
            notes: $receipt->notes,
            reversedAt: $receipt->reversed_at?->toIso8601String(),
            reversedByName: $receipt->reversedBy?->name,
            reversalReason: $receipt->reversal_reason,
            bankTransactionId: $bankTransactionId,
            bankTransactionDescription: $bankTransactionDescription,
            bankAccountLabel: $bankAccountLabel,
            expedienteId: $expedienteId,
            expedienteNumber: $expedienteNumber,
            employerName: $employerName,
        );
    }
}
