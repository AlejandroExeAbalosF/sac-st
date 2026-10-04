<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Ledger\Models\FundReceipt;

/**
 * Un renglón de lo que viaja al banco en un traslado.
 *
 * `allocationId` es `null` para la parte de un cheque que todavía no tiene
 * dueño: viaja porque el papel viaja entero, pero no financia ninguna
 * cuota. `ownedByInstallment` dice si el renglón es de la cuota que se
 * deposita o si va arrastrado por un cheque compartido.
 */
final readonly class DepositItem
{
    /** @param  numeric-string  $amount */
    public function __construct(
        public FundReceipt $receipt,
        public ?int $allocationId,
        public string $amount,
        public bool $ownedByInstallment,
    ) {}
}
