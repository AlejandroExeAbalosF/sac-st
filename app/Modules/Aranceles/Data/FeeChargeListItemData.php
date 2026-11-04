<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Data;

use App\Modules\Aranceles\Enums\FeeChargeMedium;
use App\Modules\Aranceles\Enums\FeeChargeStatus;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Un cobro en el listado: quién paga y el recibo son los del recibo vigente. */
#[TypeScript]
final readonly class FeeChargeListItemData
{
    /**
     * @param  list<FeeChargeLineData>  $lines
     */
    public function __construct(
        public int $id,
        public string $payerName,
        public ?string $payerCuit,
        public FeeChargeMedium $medium,
        public FeeChargeStatus $status,
        public ?string $paidOn,
        public string $total,
        public array $lines,
        public ?int $receiptId,
        public ?string $receiptNumber,
        public ?string $talonarioNumber,
        public ?string $voidReason,
        /** Si algo de su efectivo viajó en un depósito vigente. */
        public bool $deposited,
    ) {}
}
