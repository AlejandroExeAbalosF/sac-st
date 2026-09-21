<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

use App\Modules\Ledger\Enums\FinancialEventStatus;
use App\Modules\Ledger\Enums\PeriodClosingStatus;
use App\Modules\Ledger\Enums\PeriodType;
use Carbon\CarbonInterface;
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
 */
final class CashDaysPendingClosing
{
    public function count(int $cashBoxId, CarbonInterface $before): int
    {
        $dias = DB::table('financial_events')
            ->where('cash_box_id', $cashBoxId)
            ->where('status', FinancialEventStatus::Posted->value)
            ->whereDate('event_date', '<', $before)
            ->distinct()
            ->select('event_date');

        return DB::query()
            ->fromSub($dias, 'dias')
            ->whereNotExists(function ($query) use ($cashBoxId): void {
                $query->selectRaw('1')
                    ->from('period_closings')
                    ->where('cash_box_id', $cashBoxId)
                    ->where('period_type', PeriodType::Daily->value)
                    ->where('status', PeriodClosingStatus::Closed->value)
                    ->whereColumn('period_closings.period_from', '<=', 'dias.event_date')
                    ->whereColumn('period_closings.period_to', '>=', 'dias.event_date');
            })
            ->count();
    }
}
