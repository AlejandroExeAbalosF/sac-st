<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonInterface;

/**
 * La política de las cajas que depositan cada cobro entero (Multas M06).
 *
 * Un traslado lleva un cobro, completo, a una cuenta habilitada para la caja
 * —la elegida o la predeterminada—, y se acredita con un crédito del extracto
 * entero y sin otro uso. Haberes no la tiene: deposita por cuota. La base la impone igual
 * (`transfer_follows_box_policy`, `cash_deposit_credit_whole`); esto existe
 * para decirlo con palabras y para no ofrecer lo que la base va a rechazar.
 */
final class WholeReceiptDeposits
{
    public function __construct(private readonly CashBoxBankAccounts $cuentas) {}

    public function appliesTo(?int $cashBoxId): bool
    {
        return $cashBoxId !== null
            && (bool) CashBox::query()->whereKey($cashBoxId)->value('deposits_whole_receipts');
    }

    /**
     * La cuenta del depósito: la elegida, si está habilitada para la caja ese
     * día; sin elegir, la predeterminada, si lo está. Nula si no hay ninguna.
     */
    public function depositAccount(int $cashBoxId, Currency $currency, ?int $elegida, CarbonInterface $fecha): ?int
    {
        $cuenta = $elegida ?? $this->cuentas->defaultFor($cashBoxId, $currency)?->bank_account_id;

        return $cuenta !== null && $this->cuentas->covers($cashBoxId, $cuenta, $fecha) ? $cuenta : null;
    }
}
