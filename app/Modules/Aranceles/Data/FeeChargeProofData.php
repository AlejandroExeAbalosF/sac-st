<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Lo que dice el comprobante del depósito, y su foto. */
#[TypeScript]
final readonly class FeeChargeProofData
{
    public function __construct(
        public int $attachmentId,
        public string $amount,
        public string $depositDate,
        public ?string $operationNumber,
    ) {}
}
