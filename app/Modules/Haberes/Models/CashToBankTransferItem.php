<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Models;

use App\Modules\Banking\Models\CashToBankTransferItem as TransferItem;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * De quién era el efectivo que se depositó — §9.5 del DER.
 *
 * El ítem es de Banking; acá se le agrega lo que solo Haberes sabe: la
 * asignación de `funding_allocations`, que tiene `haber_id`. Por ese mismo
 * motivo esa tabla tampoco pudo quedar en Ledger.
 *
 * Sin esto, depositar efectivo perdería el vínculo con el beneficiario y
 * la cuota, y el dinero volvería a ser anónimo justo después de haber
 * dejado de serlo (§2.1, punto 146).
 */
final class CashToBankTransferItem extends TransferItem
{
    /** @return BelongsTo<FundingAllocation, $this> */
    public function fundingAllocation(): BelongsTo
    {
        return $this->belongsTo(FundingAllocation::class);
    }
}
