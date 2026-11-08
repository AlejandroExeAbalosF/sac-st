<?php

declare(strict_types=1);

namespace App\Modules\Multas\Support;

use App\Modules\Banking\Support\BankReceiptLinks;
use App\Modules\Multas\Models\Fine;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\DB;

final class FineBankReceiptLinks implements BankReceiptLinks
{
    public function forReceipts(array $receiptIds, ?Authorizable $viewer): array
    {
        if ($receiptIds === [] || ! ($viewer?->can('multas.ver') ?? false)) {
            return [];
        }

        $rows = DB::table('fine_payment_applications as a')
            ->whereIn('a.fund_receipt_id', $receiptIds)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fine_payment_voids as v')->whereColumn('v.fine_payment_id', 'a.fine_payment_id'))
            ->select('a.fund_receipt_id', 'a.fine_id')->distinct()->get();
        $fines = Fine::query()->with('fineCase')->whereIn('id', $rows->pluck('fine_id'))->get()->keyBy('id');

        $result = [];
        foreach ($rows as $row) {
            $fine = $fines->get($row->fine_id);
            if ($fine !== null) {
                $result[(int) $row->fund_receipt_id][] = [
                    'label' => 'Multa '.$fine->fine_number.' · '.$fine->caption,
                    'href' => route('multas.show', $fine->showParameters()),
                ];
            }
        }

        return $result;
    }
}
