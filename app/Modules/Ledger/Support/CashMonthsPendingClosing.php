<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventStatus;
use App\Modules\Ledger\Enums\PeriodClosingStatus;
use App\Modules\Ledger\Enums\PeriodType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Meses que terminaron, se movieron y todavía no se cerraron.
 *
 * **Cerrar el mes no es automático ni debería serlo**: es un acto
 * contable, no un vencimiento de calendario. Un proceso que cerrara
 * septiembre a las cero horas del primero de octubre congelaría el mes
 * aunque falte cargar un recibo del treinta, y después habría que
 * reabrirlo —con su motivo y su rastro— por algo que nadie decidió.
 *
 * Lo que sí corresponde es que no se olvide. De eso se ocupa este
 * recuento: empuja sin decidir por nadie.
 *
 * Igual que con los días, se pregunta mes por mes y no por el último
 * cierre: un mes reabierto deja un agujero en el medio que
 * `max(period_to)` pasaría por alto.
 */
final class CashMonthsPendingClosing
{
    /**
     * Sin moneda, cuenta los meses que quedaron pendientes en alguna.
     *
     * El tablero pregunta así: lo que tiene que empujar es «acá falta
     * cerrar junio», no una fila por moneda. Cuenta meses distintos, de
     * modo que un junio pendiente en pesos y en dólares sigue siendo un
     * mes y no dos.
     */
    public function count(int $cashBoxId, CarbonInterface $today, ?Currency $currency = null): int
    {
        /*
         * El mes en curso no está atrasado: todavía no terminó. Se miran
         * los movimientos anteriores al primero de este mes.
         */
        $desdeCuando = CarbonImmutable::parse($today)->startOfMonth();

        $meses = DB::table('financial_events')
            ->join('journal_lines', 'journal_lines.financial_event_id', '=', 'financial_events.id')
            ->where('financial_events.cash_box_id', $cashBoxId)
            ->where('financial_events.status', FinancialEventStatus::Posted->value)
            ->when(
                $currency !== null,
                fn (Builder $query): Builder => $query->where('journal_lines.currency', $currency->value),
            )
            ->whereDate('financial_events.event_date', '<', $desdeCuando)
            ->distinct()
            ->selectRaw("date_trunc('month', financial_events.event_date)::date AS mes, journal_lines.currency AS moneda");

        return DB::query()
            ->fromSub($meses, 'meses')
            ->whereNotExists(function (Builder $query) use ($cashBoxId): void {
                $query->selectRaw('1')
                    ->from('period_closings')
                    ->where('cash_box_id', $cashBoxId)
                    ->where('period_type', PeriodType::Monthly->value)
                    ->where('status', PeriodClosingStatus::Closed->value)
                    ->whereColumn('period_closings.currency', '=', 'meses.moneda')
                    ->whereColumn('period_closings.period_from', '=', 'meses.mes');
            })
            ->distinct()
            ->count('meses.mes');
    }
}
