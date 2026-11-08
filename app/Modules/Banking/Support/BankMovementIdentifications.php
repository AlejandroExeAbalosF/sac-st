<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Models\BankTransaction;
use App\Support\Money\Decimal;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\DB;

/** Qué hecho y qué caja explican cada importe identificado en el extracto. */
final class BankMovementIdentifications
{
    /** @param list<BankReceiptLinks> $linkProviders */
    public function __construct(private readonly array $linkProviders) {}

    /**
     * @param  list<BankTransaction>  $transactions
     * @return array<int, list<array{label: string, amount: string, links: list<array{label: string, href: string}>}>>
     */
    public function forTransactions(array $transactions, ?Authorizable $viewer = null): array
    {
        if ($transactions === []) {
            return [];
        }

        $ids = array_map(fn (BankTransaction $transaction): int => $transaction->id, $transactions);
        $rows = DB::table('bank_transaction_allocations as a')
            ->join('financial_events as e', 'e.id', '=', 'a.financial_event_id')
            ->leftJoin('cash_boxes as c', 'c.id', '=', 'e.cash_box_id')
            ->whereIn('a.bank_transaction_id', $ids)
            ->whereNull('a.reversal_of_id')
            ->selectRaw('a.id, a.bank_transaction_id, a.allocation_role, a.financial_event_id, c.name AS cash_box_name, a.amount - COALESCE((SELECT SUM(r.amount) FROM bank_transaction_allocations r WHERE r.reversal_of_id = a.id), 0) AS net_amount')
            ->get();

        $eventIds = $rows->pluck('financial_event_id')->unique()->all();
        $direct = DB::table('fund_receipts')->whereIn('financial_event_id', $eventIds)
            ->selectRaw('financial_event_id AS event_id, fund_receipt_root(id) AS receipt_id');
        $receipts = DB::table('cash_to_bank_transfers as t')
            ->join('cash_to_bank_transfer_items as i', 'i.cash_to_bank_transfer_id', '=', 't.id')
            ->whereIn('t.credit_event_id', $eventIds)->whereNotNull('i.fund_receipt_id')
            ->selectRaw('t.credit_event_id AS event_id, fund_receipt_root(i.fund_receipt_id) AS receipt_id')
            ->union($direct)->get();
        $links = [];
        $receiptIds = array_values(array_map('intval', $receipts->pluck('receipt_id')->unique()->all()));
        foreach ($this->linkProviders as $provider) {
            foreach ($provider->forReceipts($receiptIds, $viewer) as $receiptId => $items) {
                foreach ($receipts->where('receipt_id', $receiptId) as $receipt) {
                    foreach ($items as $item) {
                        $links[(int) $receipt->event_id][$item['href']] = $item;
                    }
                }
            }
        }

        $result = [];
        foreach ($rows as $row) {
            $amount = Decimal::scale((string) $row->net_amount);
            if (Decimal::equals($amount, '0')) {
                continue;
            }

            $concept = match ($row->allocation_role) {
                'cash_deposit_confirmation' => 'Depósito de efectivo acreditado',
                'payment_confirmation' => 'Pago bancario confirmado',
                default => 'Ingreso bancario registrado',
            };
            $result[(int) $row->bank_transaction_id][] = [
                'label' => $row->cash_box_name === null ? $concept : $concept.' · '.$row->cash_box_name,
                'amount' => $amount,
                'links' => array_values($links[(int) $row->financial_event_id] ?? []),
            ];
        }

        return $result;
    }
}
