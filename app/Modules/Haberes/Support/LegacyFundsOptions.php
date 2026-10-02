<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Banking\Models\BankAccount;
use App\Modules\Haberes\Data\LegacyChequeOptionData;
use App\Modules\Haberes\Data\LegacyFundsOptionsData;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Ledger\Enums\ChequeStatus;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FundReceiptOrigin;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Shared\Models\CashBox;
use App\Support\Money\Decimal;

/**
 * De dónde se puede apartar plata del sistema anterior.
 *
 * El efectivo y el depósito directo no se listan: en la apertura entraron
 * como un total y no hay forma de saber qué billete es de quién. Los
 * cheques sí: son papeles con número, y se elige cuál.
 */
final class LegacyFundsOptions
{
    public function __construct(
        private readonly CashBalance $saldos,
        private readonly InstallmentFunding $financiacion,
    ) {}

    public function for(Haber $haber): LegacyFundsOptionsData
    {
        $moneda = Currency::from($haber->currency);
        $caja = (int) CashBox::query()->active()->where('code', CashBox::HABERES)->valueOrFail('id');

        $cheques = [];

        $cartera = FundReceipt::query()
            ->where('origin', FundReceiptOrigin::Opening->value)
            ->where('cheque_status', ChequeStatus::InCustody->value)
            ->whereNull('reversal_event_id')
            ->where('cash_box_id', $caja)
            ->where('currency', $moneda->value)
            ->orderBy('id')
            ->get();

        foreach ($cartera as $cheque) {
            $libre = $this->financiacion->unallocated($cheque);

            if (Decimal::isNegative($libre) || Decimal::equals($libre, '0')) {
                continue;
            }

            $cheques[] = new LegacyChequeOptionData(
                id: $cheque->id,
                number: (string) $cheque->cheque_number,
                bank: $cheque->cheque_bank,
                amount: $cheque->amount,
                available: $libre,
                expediente: $cheque->getAttribute('expediente_number_snapshot'),
                beneficiary: $cheque->getAttribute('beneficiary_name_snapshot'),
            );
        }

        $cuentas = [];

        foreach (BankAccount::query()->where('is_active', true)->where('currency', $moneda->value)->orderBy('label')->get(['id', 'label']) as $cuenta) {
            $cuentas[] = ['id' => (int) $cuenta->id, 'label' => (string) $cuenta->label];
        }

        return new LegacyFundsOptionsData(
            pending: $this->saldos->of(LedgerAccount::LegacyFunds, $caja, $moneda),
            cheques: $cheques,
            bankAccounts: $cuentas,
        );
    }
}
