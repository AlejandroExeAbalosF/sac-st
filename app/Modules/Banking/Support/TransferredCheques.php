<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Models\CashToBankTransferItem;
use App\Modules\Ledger\Enums\ChequeStatus;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;

/**
 * El estado de los cheques que viajaron en un traslado.
 *
 * Dónde está el papel es del núcleo: la planilla de caja y el arqueo listan
 * los cheques en custodia desde `fund_receipts`, y lo que el inventario
 * llama «sin detallar» es la cuenta menos esos cheques. Un cheque
 * depositado que siguiera en custodia se contaría dos veces: en la caja y
 * en el banco.
 *
 * - al depositar, `deposited`;
 * - al cancelarse el traslado, vuelve a `in_custody`;
 * - al acreditarlo el extracto, `cleared`.
 *
 * Vive en Banking, junto al traslado, porque no necesita saber de quién era
 * el cheque: le alcanzan los ítems. Antes era un contrato que implementaba
 * Haberes, y un traslado de otra caja dependía de que Haberes se acordara de
 * él. La base controla igual que el estado coincida con el del traslado
 * (`cheque_follows_transfer`).
 */
final class TransferredCheques
{
    public function deposited(CashToBankTransfer $transfer): void
    {
        $this->move($transfer, ChequeStatus::InCustody, ChequeStatus::Deposited);
    }

    public function cancelled(CashToBankTransfer $transfer): void
    {
        $this->move($transfer, ChequeStatus::Deposited, ChequeStatus::InCustody);
    }

    public function credited(CashToBankTransfer $transfer): void
    {
        $this->move($transfer, ChequeStatus::Deposited, ChequeStatus::Cleared);
    }

    private function move(CashToBankTransfer $transfer, ChequeStatus $from, ChequeStatus $to): void
    {
        $cheques = FundReceipt::query()
            ->whereIn('id', CashToBankTransferItem::query()
                ->where('cash_to_bank_transfer_id', $transfer->id)
                ->select('fund_receipt_id'))
            ->where('medium', PaymentMedium::Cheque->value)
            ->where('cheque_status', $from->value)
            ->lockForUpdate()
            ->get();

        foreach ($cheques as $cheque) {
            $cheque->forceFill(['cheque_status' => $to])->save();
        }
    }
}
