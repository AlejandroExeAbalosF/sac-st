<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventStatus;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Lo que el libro de una caja reconoció en la cuenta bancaria, mes a mes.
 *
 * Es el movimiento neto de la cuenta Banco en el libro de la caja: lo que
 * entró —depósitos acreditados, pagos por banco verificados— menos lo que
 * el libro registró que salió. Con una cuenta compartida **no es la parte
 * de la caja en el saldo del banco**: de las salidas de la cuenta solo se
 * conocen las que el libro asentó.
 *
 * Sale del libro también para los meses cerrados: un mes cerrado no admite
 * asientos con su fecha, así que el número no cambia después del cierre.
 * Un asiento revertido y su reversión se cancelan entre sí.
 */
final class CashBankLedger
{
    /**
     * @return array<int, numeric-string> del 1 al 12
     */
    public function byMonth(int $cashBoxId, int $year, Currency $currency): array
    {
        $porMes = DB::table('journal_lines')
            ->join('financial_events', 'financial_events.id', '=', 'journal_lines.financial_event_id')
            ->where('journal_lines.cash_box_id', $cashBoxId)
            ->where('journal_lines.currency', $currency->value)
            ->where('journal_lines.account_code', LedgerAccount::BankAccount->value)
            ->whereIn('financial_events.status', [FinancialEventStatus::Posted->value, FinancialEventStatus::Reversed->value])
            ->whereBetween('financial_events.event_date', ["{$year}-01-01", "{$year}-12-31"])
            ->groupByRaw('EXTRACT(MONTH FROM financial_events.event_date)')
            ->selectRaw('EXTRACT(MONTH FROM financial_events.event_date)::int AS mes, SUM(journal_lines.debit - journal_lines.credit)::text AS neto')
            ->pluck('neto', 'mes')
            ->all();

        $meses = [];

        for ($mes = 1; $mes <= 12; $mes++) {
            $meses[$mes] = Decimal::scale(is_string($porMes[$mes] ?? null) ? $porMes[$mes] : '0');
        }

        return $meses;
    }
}
