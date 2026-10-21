<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Un depósito del cobro que se canceló: no ocurrió, y el motivo queda. */
#[TypeScript]
final class FineCancelledDepositData extends Data
{
    public function __construct(
        public int $transferId,
        public string $depositDate,
        public ?string $reason,
    ) {}
}
