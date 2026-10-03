<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

use App\Modules\Ledger\Enums\ChequeStatus;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;
use App\Support\Money\Decimal;

/**
 * Los cheques de la cartera que nadie identificó todavía.
 *
 * La apertura puede declarar los cheques en custodia **como un total**,
 * sin detallarlos uno por uno. Ese total está en `CHEQUES_IN_CUSTODY`, pero
 * no hay ningún papel con número que lo componga: es plata que el sistema
 * sabe que está y no sabe en qué cheques.
 *
 * Lo sin detallar es el saldo de la cuenta menos los cheques que sí tienen
 * su recepción y siguen en custodia. Es de ahí, y solo de ahí, de donde se
 * puede identificar un cheque nuevo del sistema anterior: más sería
 * inventar un cheque que la caja no tiene.
 *
 * Un cheque depositado deja de estar en custodia en los dos lados a la vez
 * —el asiento lo saca de la cuenta y el traslado le cambia el estado—, así
 * que no altera la resta.
 */
final class UndetailedCheques
{
    public function __construct(private readonly CashBalance $saldos) {}

    /** @return numeric-string */
    public function amount(int $cashBoxId, Currency $currency): string
    {
        $enCuenta = $this->saldos->of(LedgerAccount::ChequesInCustody, $cashBoxId, $currency);

        $detallados = FundReceipt::query()
            ->where('medium', PaymentMedium::Cheque->value)
            ->where('cheque_status', ChequeStatus::InCustody->value)
            ->whereNull('reversal_event_id')
            ->where('cash_box_id', $cashBoxId)
            ->where('currency', $currency->value)
            ->sum('amount');

        $libre = Decimal::sub($enCuenta, Decimal::scale((string) $detallados));

        return Decimal::isNegative($libre) ? '0.00' : $libre;
    }
}
