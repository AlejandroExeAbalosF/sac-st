<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** El dinero que respalda un cobro, con la fecha en que entró. */
#[TypeScript]
final readonly class FeeChargeFundingData
{
    public function __construct(
        public string $amount,
        public string $receivedOn,
    ) {}
}
