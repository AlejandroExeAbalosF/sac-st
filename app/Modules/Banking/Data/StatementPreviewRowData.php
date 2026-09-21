<?php

declare(strict_types=1);

namespace App\Modules\Banking\Data;

use App\Modules\Banking\Enums\ParseStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Support\ParsedRow;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Una fila de la vista previa.
 *
 * Viaja también la rechazada, con su motivo: saber que la fila 7 no se
 * entendió es parte de lo que el operador tiene que ver antes de decidir,
 * y esconderla haría que el total no cierre sin explicación.
 */
#[TypeScript]
final class StatementPreviewRowData extends Data
{
    public function __construct(
        public int $rowNumber,
        public ParseStatus $status,
        public ?string $errorMessage,
        public ?string $transactionDate,
        /** @var numeric-string|null */
        public ?string $amount,
        public ?TransactionDirection $direction,
        public ?string $operationId,
        public ?string $causalCode,
        public ?string $description,
        public ?string $counterpartyIdentifier,
        /** @var numeric-string|null */
        public ?string $balanceAfter,
        /**
         * El movimiento de esta fila ya está en el sistema.
         *
         * Es la fila que se va a guardar pero cuyo movimiento no se va a
         * crear de nuevo. Nulo cuando el dato no corresponde: el detalle de
         * una importación ya hecha muestra lo que pasó, no lo que pasaría.
         */
        public ?bool $repeated = null,
    ) {}

    public static function fromRow(ParsedRow $row, bool $repeated = false): self
    {
        return new self(
            rowNumber: $row->rowNumber,
            status: $row->status,
            errorMessage: $row->errorMessage,
            transactionDate: $row->transactionDate,
            amount: $row->amount,
            direction: $row->direction,
            operationId: $row->operationId,
            causalCode: $row->causalCode,
            description: $row->description,
            counterpartyIdentifier: $row->counterpartyIdentifier,
            balanceAfter: $row->balanceAfter,
            repeated: $repeated,
        );
    }
}
