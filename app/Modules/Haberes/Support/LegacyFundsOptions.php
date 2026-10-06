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
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Ledger\Support\LegacyFundsByPlace;
use App\Modules\Ledger\Support\UndetailedCheques;
use App\Modules\Shared\Models\CashBox;
use App\Support\Money\Decimal;

/**
 * De dónde se puede apartar plata del sistema anterior.
 *
 * El efectivo y el depósito directo no se listan: en la apertura entraron
 * como un total y no hay forma de saber qué billete es de quién. Sí se
 * informa cuánto queda **del sistema anterior** en cada lugar, que es el
 * tope de lo que se puede apartar de ahí. Los
 * cheques sí: son papeles con número, y se elige cuál. Si la apertura los
 * declaró como un total, sin detalle, se informa cuánto queda sin
 * identificar para cargar el cheque en el momento.
 */
final class LegacyFundsOptions
{
    public function __construct(
        private readonly CashBalance $saldos,
        private readonly InstallmentFunding $financiacion,
        private readonly UndetailedCheques $sinDetallar,
        private readonly LegacyFundsByPlace $porLugar,
    ) {}

    public function for(Haber $haber): LegacyFundsOptionsData
    {
        $moneda = Currency::from($haber->currency);
        $caja = (int) CashBox::query()->active()->where('code', CashBox::HABERES)->valueOrFail('id');

        $cheques = [];

        $cartera = FundReceipt::query()
            ->whereIn('origin', [FundReceiptOrigin::Opening->value, FundReceiptOrigin::Legacy->value])
            ->where('medium', PaymentMedium::Cheque->value)
            ->where('cheque_status', ChequeStatus::InCustody->value)
            ->whereNull('reversal_event_id')
            ->where('cash_box_id', $caja)
            ->where('currency', $moneda->value)
            ->orderBy('id')
            ->get();

        foreach ($cartera as $cheque) {
            // Solo los enteros y libres: un cheque se aparta completo.
            $libre = $this->financiacion->unallocated($cheque);

            if (! Decimal::equals($libre, $cheque->amount)) {
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
            $cuentas[] = [
                'id' => (int) $cuenta->id,
                'label' => (string) $cuenta->label,
                'available' => $this->porLugar->bankAccount($caja, $moneda, (int) $cuenta->id),
            ];
        }

        return new LegacyFundsOptionsData(
            pending: $this->saldos->of(LedgerAccount::LegacyFunds, $caja, $moneda),
            cash: $this->porLugar->cash($caja, $moneda),
            cheques: $cheques,
            undetailedCheques: $this->sinDetallar->amount($caja, $moneda),
            bankAccounts: $cuentas,
        );
    }
}
