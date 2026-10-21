<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Modules\Shared\Models\CashBox;

/**
 * La política de las cajas que depositan cada cobro entero (Multas M06).
 *
 * Un traslado lleva un cobro, completo, a la cuenta con la que opera el
 * libro de la caja, y se acredita con un crédito del extracto entero y sin
 * otro uso. Haberes no la tiene: deposita por cuota. La base la impone igual
 * (`transfer_follows_box_policy`, `cash_deposit_credit_whole`); esto existe
 * para decirlo con palabras y para no ofrecer lo que la base va a rechazar.
 */
final class WholeReceiptDeposits
{
    public function appliesTo(?int $cashBoxId): bool
    {
        return $cashBoxId !== null
            && (bool) CashBox::query()->whereKey($cashBoxId)->value('deposits_whole_receipts');
    }

    /** La cuenta con la que opera el libro de la caja en esa moneda, si está abierto. */
    public function accountFor(int $cashBoxId, Currency $currency): ?int
    {
        return CashBookOpening::for($cashBoxId, $currency)?->bank_account_id;
    }
}
