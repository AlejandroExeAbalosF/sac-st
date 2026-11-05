<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Un renglón de un cobro, para listarlo o para corregirlo. */
#[TypeScript]
final readonly class FeeChargeLineData
{
    public function __construct(
        public int $itemId,
        public int $feeTypeId,
        public int $code,
        public string $name,
        public ?int $quantity,
        public ?string $baseAmount,
        public string $calculatedAmount,
        public string $amount,
        public ?string $reasonCode,
        public ?string $reason,
    ) {}
}
