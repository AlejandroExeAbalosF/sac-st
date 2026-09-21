<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Enums\ResidualStatus;
use App\Modules\Ledger\Models\FundReceipt;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Una recepción en el listado.
 *
 * `unallocated` es el dato que ordena la pantalla: es la plata que entró y
 * todavía no se sabe de quién es. Llega calculado, nunca leído de una
 * columna (§5.1).
 */
#[TypeScript]
final class FundReceiptListItemData extends Data
{
    public function __construct(
        public int $id,
        public string $receivedDate,
        public PaymentMedium $medium,
        /** @var numeric-string */
        public string $amount,
        /** @var numeric-string */
        public string $unallocated,
        public ResidualStatus $residualStatus,
        public ?string $depositorName,
        public ?string $notes,
        /** El expediente que trajo el comprobante, si el ticket llegó a vincularse. */
        /*
         * Revertida: su dinero salió de los libros. Sin esto el listado la
         * muestra igual que una viva, y como no tiene asignaciones aparece
         * además pidiendo que alguien la reparta.
         */
        public ?string $reversedAt,
        public ?string $expedienteNumber,
        public ?int $expedienteId,
    ) {}

    /** @param  numeric-string  $unallocated */
    public static function fromModel(
        FundReceipt $receipt,
        string $unallocated,
        ?string $expedienteNumber = null,
        ?int $expedienteId = null,
    ): self {
        return new self(
            id: $receipt->id,
            receivedDate: $receipt->received_date->format('Y-m-d'),
            medium: $receipt->medium,
            amount: $receipt->amount,
            unallocated: $unallocated,
            residualStatus: $receipt->residual_status,
            depositorName: $receipt->depositor?->name,
            notes: $receipt->notes,
            reversedAt: $receipt->reversed_at?->toIso8601String(),
            expedienteNumber: $expedienteNumber,
            expedienteId: $expedienteId,
        );
    }
}
