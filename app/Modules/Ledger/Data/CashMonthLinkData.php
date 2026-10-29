<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Un enlace o una acción del panel del mes. */
#[TypeScript]
final class CashMonthLinkData extends Data
{
    public function __construct(
        public string $label,
        public string $href,
    ) {}
}
