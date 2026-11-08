<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Support;

use App\Modules\Banking\Support\BankReceiptLinks;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\DB;

final class FeeBankReceiptLinks implements BankReceiptLinks
{
    public function forReceipts(array $receiptIds, ?Authorizable $viewer): array
    {
        if ($receiptIds === [] || ! ($viewer?->can('aranceles.ver') ?? false)) {
            return [];
        }

        $rows = DB::table('fee_charge_fundings as f')
            ->join('fee_charge_receipts as cr', 'cr.fee_charge_id', '=', 'f.fee_charge_id')
            ->join('receipts as r', 'r.id', '=', 'cr.receipt_id')
            ->whereIn('f.fund_receipt_id', $receiptIds)
            ->whereNull('f.released_at')
            ->where('r.status', 'issued')
            ->select('f.fund_receipt_id', 'r.id', 'r.formatted_number')
            ->distinct()->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->fund_receipt_id][] = [
                'label' => 'Recibo de arancel '.$row->formatted_number,
                'href' => route('aranceles.recibos.view', ['receipt' => $row->id]),
            ];
        }

        return $result;
    }
}
