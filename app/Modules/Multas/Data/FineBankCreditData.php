<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Models\BankTransaction;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un crédito de la cuenta de Multas anterior a la apertura de la caja: el
 * que puede respaldar un pago anterior al sistema.
 */
#[TypeScript]
final class FineBankCreditData extends Data
{
    public function __construct(
        public int $id,
        public string $date,
        /** @var numeric-string */
        public string $amount,
        public ?string $description,
        public ?string $counterparty,
        public ?string $operationId,
        /** Lo dejaron fuera del circuito: ya estaba en el saldo anterior a la caja. */
        public bool $ignored,
    ) {}

    public static function fromModel(BankTransaction $credito): self
    {
        return new self(
            id: $credito->id,
            date: (string) $credito->transaction_date?->toDateString(),
            amount: $credito->amount,
            description: $credito->description,
            counterparty: $credito->counterparty_name,
            operationId: $credito->operation_id,
            ignored: $credito->reconciliation_status === ReconciliationStatus::Ignored,
        );
    }
}
