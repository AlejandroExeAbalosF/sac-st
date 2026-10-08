<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventStatus;
use App\Modules\Ledger\Enums\PeriodClosingStatus;
use App\Modules\Ledger\Enums\PeriodType;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Días operativos que se movieron y todavía no se cerraron.
 *
 * No es «¿se cerró hoy?»: el día en curso no está atrasado, y contarlo
 * daría un uno permanente que nadie leería. Lo que importa es lo que
 * quedó atrás sin cerrar, que es lo que después obliga a reabrir períodos
 * para poder asentar una corrección.
 *
 * Se pregunta por cada día y no por el último cierre: un período reabierto
 * deja un agujero en el medio, y `max(period_to)` lo pasaría por encima
 * como si estuviera cerrado.
 *
 * **Por moneda**, como `CashMonthsPendingClosing`: pesos y dólares se
 * cierran por separado, y un día cerrado en dólares no cierra lo que se
 * movió en pesos. Sin moneda cuenta los días que tienen algún libro
 * pendiente, una vez cada uno.
 */
final class CashDaysPendingClosing
{
    public function count(int $cashBoxId, CarbonInterface $before, ?Currency $currency = null): int
    {
        $dias = DB::table('financial_events')
            ->join('journal_lines', 'journal_lines.financial_event_id', '=', 'financial_events.id')
            ->where('financial_events.cash_box_id', $cashBoxId)
            ->where('financial_events.status', FinancialEventStatus::Posted->value)
            ->when(
                $currency !== null,
                fn (Builder $query): Builder => $query->where('journal_lines.currency', $currency->value),
            )
            ->whereDate('financial_events.event_date', '<', $before)
            ->distinct()
            ->selectRaw('financial_events.event_date AS dia, journal_lines.currency AS moneda');

        return DB::query()
            ->fromSub($dias, 'dias')
            ->whereNotExists(function (Builder $query) use ($cashBoxId): void {
                $query->selectRaw('1')
                    ->from('period_closings')
                    ->where('cash_box_id', $cashBoxId)
                    ->where('period_type', PeriodType::Daily->value)
                    ->where('status', PeriodClosingStatus::Closed->value)
                    ->whereColumn('period_closings.currency', '=', 'dias.moneda')
                    ->whereColumn('period_closings.period_from', '<=', 'dias.dia')
                    ->whereColumn('period_closings.period_to', '>=', 'dias.dia');
            })
            ->distinct()
            ->count('dias.dia');
    }
}
