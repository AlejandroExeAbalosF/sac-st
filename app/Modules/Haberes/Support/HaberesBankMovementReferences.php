<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Support\BankMovementReferences;
use App\Support\Money\Decimal;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Las cuotas relacionadas con los movimientos visibles en Banco. */
final class HaberesBankMovementReferences implements BankMovementReferences
{
    public function forTransactions(array $transactions, ?Authorizable $viewer): array
    {
        if ($transactions === [] || ! ($viewer?->can('expedientes.ver') ?? false)) {
            return [];
        }

        $ids = array_map(fn (BankTransaction $transaction): int => $transaction->id, $transactions);
        $porMovimiento = [];

        // El cruce identifica el comprobante, pero todavía no financia la cuota.
        $principales = $this->conCuota(DB::table('deposit_tickets as dt'), 'dt.beneficiary_installment_id')
            ->whereIn('dt.bank_transaction_id', $ids)
            ->selectRaw('dt.bank_transaction_id AS transaction_id, dt.beneficiary_installment_id AS installment_id, bi.installment_number, h.id AS haber_id, h.haber_number, e.id AS expediente_id, e.display_number, p.name AS beneficiary')
            ->get();
        $adicionales = $this->conCuota(DB::table('deposit_ticket_additional_transactions as dta')
            ->join('deposit_tickets as dt', 'dt.id', '=', 'dta.deposit_ticket_id'), 'dt.beneficiary_installment_id')
            ->whereIn('dta.bank_transaction_id', $ids)
            ->selectRaw('dta.bank_transaction_id AS transaction_id, dt.beneficiary_installment_id AS installment_id, bi.installment_number, h.id AS haber_id, h.haber_number, e.id AS expediente_id, e.display_number, p.name AS beneficiary')
            ->get();
        foreach ($principales->concat($adicionales) as $row) {
            $porMovimiento[$row->transaction_id][$row->installment_id] = $this->reference($row, 'ticket_matched');
        }

        // Solo el neto vigente: liberar o revertir fondos no deja una cuota falsamente imputada.
        $imputaciones = $this->conCuota(DB::table('bank_transaction_allocations as bta')
            ->join('fund_receipts as fr', 'fr.financial_event_id', '=', 'bta.financial_event_id')
            ->join('funding_allocations as fa', 'fa.fund_receipt_id', '=', 'fr.id'), 'fa.beneficiary_installment_id')
            ->whereIn('bta.bank_transaction_id', $ids)
            ->whereNull('bta.reversal_of_id')
            ->whereNull('fr.reversal_event_id')
            ->selectRaw("bta.bank_transaction_id AS transaction_id, fa.beneficiary_installment_id AS installment_id, bi.installment_number, h.id AS haber_id, h.haber_number, e.id AS expediente_id, e.display_number, p.name AS beneficiary, SUM(CASE WHEN fa.allocation_kind = 'reversal' THEN -fa.amount ELSE fa.amount END) AS net_amount")
            ->groupBy('bta.bank_transaction_id', 'fa.beneficiary_installment_id', 'bi.installment_number', 'h.id', 'h.haber_number', 'e.id', 'e.display_number', 'p.name')
            ->get();
        foreach ($imputaciones as $row) {
            $amount = Decimal::scale((string) $row->net_amount);
            if (! Decimal::equals($amount, '0')) {
                $porMovimiento[$row->transaction_id][$row->installment_id] = $this->reference($row, 'funds_allocated', $amount);
            }
        }

        // Un crédito puede ser la acreditación de efectivo que ya pertenecía
        // a una cuota antes de salir de la caja. No es un ingreso nuevo.
        $traslados = $this->conCuota(DB::table('bank_transaction_allocations as bta')
            ->join('cash_to_bank_transfers as t', 't.credit_event_id', '=', 'bta.financial_event_id')
            ->join('cash_to_bank_transfer_items as item', 'item.cash_to_bank_transfer_id', '=', 't.id')
            ->join('funding_allocations as fa', 'fa.id', '=', 'item.funding_allocation_id'), 'fa.beneficiary_installment_id')
            ->whereIn('bta.bank_transaction_id', $ids)
            ->where('bta.allocation_role', 'cash_deposit_confirmation')
            ->whereNull('bta.reversal_of_id')
            ->where('t.status', 'bank_confirmed')
            ->selectRaw('bta.bank_transaction_id AS transaction_id, fa.beneficiary_installment_id AS installment_id, bi.installment_number, h.id AS haber_id, h.haber_number, e.id AS expediente_id, e.display_number, p.name AS beneficiary, SUM(item.amount) AS deposited_amount')
            ->groupBy('bta.bank_transaction_id', 'fa.beneficiary_installment_id', 'bi.installment_number', 'h.id', 'h.haber_number', 'e.id', 'e.display_number', 'p.name')
            ->get();
        foreach ($traslados as $row) {
            $anterior = $porMovimiento[$row->transaction_id][$row->installment_id]['amount'] ?? null;
            $porMovimiento[$row->transaction_id][$row->installment_id] = $this->creditedTransfer(
                $row, Decimal::scale((string) $row->deposited_amount), $anterior,
            );
        }

        // Un cheque viaja entero: el ítem cita la recepción y puede financiar
        // varias cuotas, incluso después de haber llegado al banco.
        $cheques = $this->conCuota(DB::table('bank_transaction_allocations as bta')
            ->join('cash_to_bank_transfers as t', 't.credit_event_id', '=', 'bta.financial_event_id')
            ->join('cash_to_bank_transfer_items as item', 'item.cash_to_bank_transfer_id', '=', 't.id')
            ->join('fund_receipts as fr', 'fr.id', '=', 'item.fund_receipt_id')
            ->join('funding_allocations as fa', 'fa.fund_receipt_id', '=', 'fr.id'), 'fa.beneficiary_installment_id')
            ->whereIn('bta.bank_transaction_id', $ids)
            ->where('bta.allocation_role', 'cash_deposit_confirmation')
            ->whereNull('bta.reversal_of_id')
            ->where('t.status', 'bank_confirmed')
            ->whereNull('item.funding_allocation_id')
            ->where('fr.medium', 'cheque')
            ->whereNull('fr.reversal_event_id')
            ->selectRaw("bta.bank_transaction_id AS transaction_id, fa.beneficiary_installment_id AS installment_id, bi.installment_number, h.id AS haber_id, h.haber_number, e.id AS expediente_id, e.display_number, p.name AS beneficiary, SUM(CASE WHEN fa.allocation_kind = 'reversal' THEN -fa.amount ELSE fa.amount END) AS deposited_amount")
            ->groupBy('bta.bank_transaction_id', 'fa.beneficiary_installment_id', 'bi.installment_number', 'h.id', 'h.haber_number', 'e.id', 'e.display_number', 'p.name')
            ->get();
        foreach ($cheques as $row) {
            $amount = Decimal::scale((string) $row->deposited_amount);
            if (! Decimal::equals($amount, '0')) {
                $anterior = $porMovimiento[$row->transaction_id][$row->installment_id]['amount'] ?? null;
                $porMovimiento[$row->transaction_id][$row->installment_id] = $this->creditedTransfer(
                    $row, $amount, $anterior,
                );
            }
        }

        $egresos = $this->conCuota(DB::table('disbursements as d'), 'd.beneficiary_installment_id')
            ->whereIn('d.bank_transaction_id', $ids)
            ->whereNotIn('d.status', ['reversed', 'failed'])
            ->selectRaw('d.bank_transaction_id AS transaction_id, d.beneficiary_installment_id AS installment_id, bi.installment_number, h.id AS haber_id, h.haber_number, e.id AS expediente_id, e.display_number, p.name AS beneficiary, d.amount, d.status')
            ->get();
        foreach ($egresos as $row) {
            $porMovimiento[$row->transaction_id][$row->installment_id] = $this->reference(
                $row,
                $row->status === 'confirmed' ? 'payment_confirmed' : 'debit_linked',
                Decimal::scale((string) $row->amount),
            );
        }

        return array_map(fn (array $references): array => array_values($references), $porMovimiento);
    }

    private function conCuota(Builder $query, string $installmentColumn): Builder
    {
        return $query
            ->join('beneficiary_installments as bi', 'bi.id', '=', $installmentColumn)
            ->join('haberes as h', 'h.id', '=', 'bi.haber_id')
            ->join('expedientes as e', 'e.id', '=', 'h.expediente_id')
            ->join('people as p', 'p.id', '=', 'h.beneficiary_id');
    }

    /** @return array{installmentId: int, expediente: string, beneficiary: string, haber: int, installment: int, status: string, amount: string|null, href: string} */
    private function creditedTransfer(stdClass $row, string $amount, ?string $previous): array
    {
        return $this->reference(
            $row,
            'cash_deposit_credited',
            $previous === null ? $amount : Decimal::add($previous, $amount),
        );
    }

    /**
     * @return array{installmentId: int, expediente: string, beneficiary: string, haber: int, installment: int, status: string, amount: string|null, href: string}
     */
    private function reference(stdClass $row, string $status, ?string $amount = null): array
    {
        return [
            'installmentId' => (int) $row->installment_id,
            'expediente' => $row->display_number,
            'beneficiary' => $row->beneficiary,
            'haber' => (int) $row->haber_number,
            'installment' => (int) $row->installment_number,
            'status' => $status,
            'amount' => $amount,
            'href' => route('haberes.haber.show', [
                'expediente' => $row->expediente_id,
                'haber' => $row->haber_number,
                'cuota' => $row->installment_id,
            ]),
        ];
    }
}
