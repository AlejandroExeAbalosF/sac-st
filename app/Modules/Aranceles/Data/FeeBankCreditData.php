<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Un crédito del extracto que se puede identificar como arancel. */
#[TypeScript]
final readonly class FeeBankCreditData
{
    public function __construct(
        public int $id,
        public string $date,
        public string $amount,
        /** Lo que le queda sin imputar: un crédito puede pagar más de un arancel. */
        public string $free,
        public ?string $description,
        public ?string $counterparty,
        public ?string $operationId,
        public string $accountLabel,
    ) {}
}
