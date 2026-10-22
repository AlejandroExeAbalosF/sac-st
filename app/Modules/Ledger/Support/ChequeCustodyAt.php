<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

use App\Modules\Ledger\Models\PeriodClosing;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Los cheques que estaban en el cajón al terminar un período.
 *
 * **Es la custodia a la fecha del cierre, no la de hoy**: los cheques
 * recibidos hasta ese día, menos los revertidos, depositados o rechazados
 * hasta ese día. Filtrar por `cheque_status` respondía qué sigue en
 * custodia ahora, y un cierre hecho tarde —o uno reabierto y vuelto a
 * cerrar— perdía cheques que ese día estaban en el cajón mientras su saldo
 * los seguía contando. Si el 30/09 estaba el cheque A y el 02/10 se
 * rechazó y llegó B en su lugar, septiembre lista A y octubre lista B: el
 * reemplazo es otra recepción, con su propia fecha.
 *
 * Todo se juzga por la fecha del asiento, que es la que mueve el saldo de
 * `CHEQUES_IN_CUSTODY`: así el inventario y el saldo del cierre dicen lo
 * mismo. Depósitos y rechazos se leen por tabla y sin su modelo, con el
 * mismo criterio con el que `journal_lines` apunta a una cuenta bancaria.
 *
 * Es la parte que vale para cualquier caja. Las salidas propias de cada
 * módulo —en Haberes, el cheque que se entrega al beneficiario— y quién es
 * cada cheque las agrega el módulo (`CashBoxModule::chequesInCustody`).
 */
final class ChequeCustodyAt
{
    public function query(PeriodClosing $closing): Builder
    {
        $hasta = $closing->period_to;

        return DB::table('fund_receipts')
            ->join('financial_events AS reception', 'reception.id', '=', 'fund_receipts.financial_event_id')
            ->whereDate('reception.event_date', '<=', $hasta)
            ->whereDate('fund_receipts.received_date', '<=', $hasta)
            ->where('fund_receipts.cash_box_id', $closing->cash_box_id)
            ->where('fund_receipts.currency', $closing->currency->value)
            ->where('fund_receipts.medium', 'cheque')
            ->whereNotExists(function ($query) use ($hasta): void {
                $query->selectRaw('1')->from('financial_events AS reversal')
                    ->whereColumn('reversal.id', 'fund_receipts.reversal_event_id')
                    ->whereDate('reversal.event_date', '<=', $hasta);
            })
            ->whereNotExists(function ($query) use ($hasta): void {
                $query->selectRaw('1')->from('cash_to_bank_transfer_items AS item')
                    ->join('cash_to_bank_transfers AS transfer', 'transfer.id', '=', 'item.cash_to_bank_transfer_id')
                    ->join('financial_events AS deposit', 'deposit.id', '=', 'transfer.deposit_event_id')
                    ->whereColumn('item.fund_receipt_id', 'fund_receipts.id')
                    ->whereDate('deposit.event_date', '<=', $hasta)
                    ->whereNotExists(function ($reversal) use ($hasta): void {
                        $reversal->selectRaw('1')->from('financial_events AS undone')
                            ->whereColumn('undone.reversal_of_id', 'deposit.id')
                            ->whereDate('undone.event_date', '<=', $hasta);
                    });
            })
            /*
             * Un cheque rechazado en el cajón sale de la custodia el día del
             * rechazo. Uno rechazado después de depositarse ya había salido
             * con el depósito, y esto no lo cambia.
             */
            ->whereNotExists(function ($query) use ($hasta): void {
                $query->selectRaw('1')->from('cheque_rejections AS rejection')
                    ->join('financial_events AS rejected', 'rejected.id', '=', 'rejection.rejection_event_id')
                    ->whereColumn('rejection.fund_receipt_id', 'fund_receipts.id')
                    ->whereDate('rejected.event_date', '<=', $hasta);
            });
    }
}
