<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Una parte de efectivo: de qué cobro o de la apertura sale, y cuánto. */
#[TypeScript]
final class FeeCashShareData extends Data
{
    public function __construct(
        public string $amount,
        public string $receivedOn,
        /** Es lo que la caja traía al abrir: no tiene recibo. */
        public bool $opening,
        public ?string $receiptNumber,
        public ?string $payerName,
    ) {}
}
