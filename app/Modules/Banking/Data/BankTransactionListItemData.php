<?php

declare(strict_types=1);

namespace App\Modules\Banking\Data;

use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankTransaction;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Un movimiento bancario en el listado. */
#[TypeScript]
final class BankTransactionListItemData extends Data
{
    public function __construct(
        public int $id,
        public string $transactionDate,
        /** @var numeric-string */
        public string $amount,
        public TransactionDirection $direction,
        public ?string $operationId,
        public ?string $causalCode,
        public ?string $description,
        /**
         * CUIT que el sistema encontró dentro del concepto. Es exacto
         * —se verifica el dígito— y es lo que después va a permitir
         * sugerir de quién es el ingreso.
         */
        public ?string $counterpartyIdentifier,
        public ?string $counterpartyName,
        /** @var numeric-string|null */
        public ?string $balanceAfter,
        public ReconciliationStatus $reconciliationStatus,
        public ?string $ignoredReason,
        /** Cuántas veces apareció en un extracto: más de una es normal. */
        public int $seenInImports,
    ) {}

    public static function fromModel(BankTransaction $transaction): self
    {
        return new self(
            id: $transaction->id,
            transactionDate: $transaction->transaction_date?->format('Y-m-d') ?? '',
            amount: $transaction->amount,
            direction: $transaction->direction,
            operationId: $transaction->operation_id,
            causalCode: $transaction->causal_code,
            description: $transaction->description,
            counterpartyIdentifier: $transaction->counterparty_identifier,
            counterpartyName: $transaction->counterparty_name,
            balanceAfter: $transaction->balance_after,
            reconciliationStatus: $transaction->reconciliation_status,
            ignoredReason: $transaction->ignored_reason,
            // Sin `getAttribute`: el modelo corre con
            // `preventAccessingMissingAttributes` y este agregado solo
            // existe cuando la consulta hizo el `withCount`.
            seenInImports: (int) ($transaction->getAttributes()['statement_rows_count'] ?? 0),
        );
    }
}
