<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

use App\Modules\Ledger\Data\CalendarDayData;
use App\Modules\Ledger\Data\CalendarMonthData;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventStatus;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\PeriodClosingStatus;
use App\Modules\Ledger\Enums\PeriodType;
use App\Modules\Ledger\Models\CashCount;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El calendario de la caja: qué pasó cada día y cómo viene cada mes.
 *
 * Es una vista, no un concepto nuevo del dominio. Los cierres siguen siendo
 * dos —diario y mensual—; el año existe acá solo como nivel de navegación,
 * porque doce meses entran en una pantalla y trescientos días no.
 *
 * **Lo que aporta sobre la lista es lo que la lista no puede mostrar: los
 * días que faltan.** Una lista enumera lo que existe, y en un cierre de caja
 * lo que importa es lo contrario — el martes que tuvo movimientos y quedó
 * sin cerrar no aparece en ninguna lista de cierres, justamente porque no
 * tiene uno.
 */
final class CashCalendar
{
    private const MESES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    /**
     * La grilla de un mes, de lunes a domingo.
     *
     * Incluye los días de relleno del mes anterior y el siguiente para que
     * las semanas queden completas: una grilla que empieza en miércoles se
     * lee mal, y el operador cuenta columnas en vez de mirar.
     *
     * @return list<CalendarDayData>
     */
    public function month(int $cashBoxId, int $year, int $month, Currency $currency = Currency::Ars): array
    {
        $primero = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $ultimo = $primero->endOfMonth()->startOfDay();

        $desde = $primero->startOfWeek(CarbonImmutable::MONDAY);
        $hasta = $ultimo->endOfWeek(CarbonImmutable::SUNDAY);

        $cierres = $this->dailyClosings($cashBoxId, $desde, $hasta, $currency);
        $actividad = $this->activityByDay($cashBoxId, $desde, $hasta, $currency);
        $arqueos = $this->counts($cashBoxId, $desde, $hasta, $currency);
        $conPlanilla = $this->sheets($this->idsOf($cierres));

        $hoy = CarbonImmutable::now()->startOfDay();
        $dias = [];

        for ($dia = $desde; $dia->lessThanOrEqualTo($hasta); $dia = $dia->addDay()) {
            $clave = $dia->toDateString();
            $cierre = $cierres->get($clave);
            $arqueo = $arqueos->get($clave);
            $movimientos = array_key_exists($clave, $actividad);

            $dias[] = new CalendarDayData(
                date: $clave,
                day: $dia->day,
                inMonth: $dia->month === $month && $dia->year === $year,
                isToday: $dia->equalTo($hoy),
                isWeekend: $dia->isWeekend(),
                state: $this->state($dia, $hoy, $cierre, $movimientos),
                hasMovements: $movimientos,
                onlyReversals: $actividad[$clave] ?? false,
                closingCash: $cierre?->closing_cash,
                closingId: $cierre === null ? null : (int) $cierre->id,
                countBalanced: $arqueo?->isBalanced(),
                partiallyCounted: $arqueo !== null && $arqueo->isBalanced() && ! $arqueo->wasFullyCounted(),
                hasSheet: $cierre !== null && in_array((int) $cierre->id, $conPlanilla, true),
            );
        }

        return $dias;
    }

    /**
     * Los doce meses de un año.
     *
     * @return list<CalendarMonthData>
     */
    public function year(int $cashBoxId, int $year, Currency $currency = Currency::Ars): array
    {
        $desde = CarbonImmutable::create($year, 1, 1)->startOfDay();
        $hasta = $desde->endOfYear()->startOfDay();

        $mensuales = PeriodClosing::query()
            ->where('cash_box_id', $cashBoxId)
            ->where('currency', $currency)
            ->where('period_type', PeriodType::Monthly)
            ->whereBetween('period_from', [$desde, $hasta])
            ->get()
            ->keyBy(fn (PeriodClosing $c): int => $c->period_from->month);

        $diarios = $this->dailyClosings($cashBoxId, $desde, $hasta, $currency);
        $conMovimiento = array_keys($this->activityByDay($cashBoxId, $desde, $hasta, $currency));

        $conPlanilla = $this->sheets($this->idsOf($mensuales));
        $hoy = CarbonImmutable::now()->startOfDay();

        $meses = [];

        foreach (self::MESES as $numero => $nombre) {
            $inicio = CarbonImmutable::create($year, $numero, 1)->startOfDay();

            $delMes = $diarios->filter(
                fn (PeriodClosing $c): bool => $c->period_from->month === $numero
                    && $c->period_from->year === $year
            );

            $mensual = $mensuales->get($numero);

            $meses[] = new CalendarMonthData(
                month: $numero,
                label: $nombre,
                closed: $mensual?->status === PeriodClosingStatus::Closed,
                closingId: $mensual === null ? null : (int) $mensual->id,
                hasSheet: $mensual !== null && in_array((int) $mensual->id, $conPlanilla, true),
                daysClosed: $delMes->count(),
                daysWithMovements: count(array_filter(
                    $conMovimiento,
                    fn (string $fecha): bool => str_starts_with($fecha, $inicio->format('Y-m')),
                )),
                /*
                 * El saldo del cierre mensual si existe; si no, el del
                 * último día cerrado, que es lo más cerca que se puede
                 * estar de «con cuánto terminó el mes» sin inventar nada.
                 */
                closingCash: $this->closingCashOf($mensual, $delMes),
                isFuture: $inicio->greaterThan($hoy),
            );
        }

        return $meses;
    }

    /**
     * Cuántos días de cada mes tuvieron movimientos y quedaron sin cerrar.
     *
     * **No es una regla del DER.** El invariante 30 pide que no haya
     * eventos en borrador, importaciones en curso ni arqueos sin resolver;
     * no dice nada sobre días sin cerrar, y cerrar el mes con alguno no
     * falsea sus totales — se calculan sobre el libro, no sobre los cierres
     * diarios.
     *
     * Lo que sí produce es un libro incompleto —le faltan las hojas de esos
     * días— y una incoherencia entre dos pantallas del mismo circuito: el
     * calendario los pinta en rojo y el diálogo de cierre los ignora. Por
     * eso esto alimenta **un aviso, no un bloqueo**.
     *
     * @return array<string, int> indexado por `YYYY-MM`
     */
    public function pendingDaysByMonth(
        int $cashBoxId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        Currency $currency = Currency::Ars,
    ): array {
        $conMovimiento = array_keys($this->activityByDay($cashBoxId, $from, $to, $currency));

        $cerrados = [];

        foreach ($this->dailyClosings($cashBoxId, $from, $to, $currency) as $cierre) {
            $cerrados[$cierre->period_from->toDateString()] = true;
        }

        $pendientes = [];

        foreach ($conMovimiento as $fecha) {
            if (isset($cerrados[$fecha])) {
                continue;
            }

            $mes = mb_substr($fecha, 0, 7);
            $pendientes[$mes] = ($pendientes[$mes] ?? 0) + 1;
        }

        return $pendientes;
    }

    /**
     * Los identificadores de una colección de cierres, como lista.
     *
     * Se arma con un bucle y no con `pluck`: el análisis estático no puede
     * ver que una colección produzca una lista con índices correlativos, y
     * la garantía importa porque `whereIn` la recibe.
     *
     * Recibe `iterable` y no `Collection` porque solo recorre: las claves
     * de una colección no son covariantes, y tipar el parámetro con ellas
     * obliga a que quien llama tenga exactamente la misma forma de clave.
     *
     * @param  iterable<PeriodClosing>  $closings
     * @return list<int>
     */
    private function idsOf(iterable $closings): array
    {
        $ids = [];

        foreach ($closings as $cierre) {
            $ids[] = (int) $cierre->id;
        }

        return $ids;
    }

    /**
     * Con cuánto terminó el mes.
     *
     * El saldo del cierre mensual si existe; si no, el del último día
     * cerrado, que es lo más cerca que se puede estar de la respuesta sin
     * inventar nada. Un mes sin ningún cierre no tiene saldo que mostrar.
     *
     * @param  Collection<string, PeriodClosing>  $daily
     * @return numeric-string|null
     */
    private function closingCashOf(?PeriodClosing $monthly, Collection $daily): ?string
    {
        if ($monthly !== null) {
            return $monthly->closing_cash;
        }

        $ultimo = $daily->sortBy(fn (PeriodClosing $c): string => $c->period_from->toDateString())->last();

        return $ultimo?->closing_cash;
    }

    /**
     * En qué estado se ve un día.
     *
     * Los cinco son los que hacen falta para leer la grilla sin pensar: lo
     * cerrado está bien, lo reabierto pide atención, lo pendiente es lo que
     * hay que hacer, lo tranquilo es un día sin trabajo, y el futuro no es
     * nada todavía.
     */
    private function state(
        CarbonImmutable $dia,
        CarbonImmutable $hoy,
        ?PeriodClosing $cierre,
        bool $conMovimientos,
    ): string {
        if ($cierre?->status === PeriodClosingStatus::Closed) {
            return 'closed';
        }

        if ($cierre?->status === PeriodClosingStatus::Reopened) {
            return 'reopened';
        }

        if ($dia->greaterThan($hoy)) {
            return 'future';
        }

        return $conMovimientos ? 'pending' : 'quiet';
    }

    /** @return Collection<string, PeriodClosing> */
    private function dailyClosings(
        int $cashBoxId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        Currency $currency,
    ): Collection {
        return PeriodClosing::query()
            ->where('cash_box_id', $cashBoxId)
            ->where('currency', $currency)
            ->where('period_type', PeriodType::Daily)
            ->whereBetween('period_from', [$from, $to])
            ->get()
            ->keyBy(fn (PeriodClosing $c): string => $c->period_from->toDateString());
    }

    /**
     * Las fechas que tuvieron algún asiento y si fueron solo reversiones.
     *
     * Es lo que distingue un día tranquilo de uno que quedó sin cerrar, y
     * la única forma de saberlo es preguntarle al libro: la tabla de
     * cierres, por definición, no sabe nada de los días que no tienen uno.
     *
     * Una reversión cambia el libro y sigue contando como movimiento para
     * los cierres. La grilla la distingue porque puede no haber un
     * comprobante en el libro del día que la explique.
     *
     * @return array<string, bool> fecha => solo reversiones
     */
    private function activityByDay(
        int $cashBoxId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        Currency $currency,
    ): array {
        $eventos = DB::table('financial_events')
            ->where('cash_box_id', $cashBoxId)
            ->whereIn('status', [
                FinancialEventStatus::Posted->value,
                FinancialEventStatus::Reversed->value,
            ])
            ->whereBetween('event_date', [$from->toDateString(), $to->toDateString()])
            ->whereExists(function ($query) use ($currency): void {
                $query->selectRaw('1')
                    ->from('journal_lines')
                    ->whereColumn('journal_lines.financial_event_id', 'financial_events.id')
                    ->where('journal_lines.currency', $currency->value);
            })
            ->select('event_date')
            ->selectRaw(
                'MAX(CASE WHEN event_type = ? THEN 0 ELSE 1 END) AS has_other_events',
                [FinancialEventType::Reversal->value],
            )
            ->groupBy('event_date')
            ->get();

        $actividad = [];

        foreach ($eventos as $evento) {
            $fecha = CarbonImmutable::parse((string) $evento->event_date)->toDateString();
            $actividad[$fecha] = (int) $evento->has_other_events === 0;
        }

        return $actividad;
    }

    /** @return Collection<string, CashCount> */
    private function counts(
        int $cashBoxId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        Currency $currency,
    ): Collection {
        return CashCount::query()
            ->where('cash_box_id', $cashBoxId)
            ->where('currency', $currency)
            ->whereBetween('counted_on', [$from, $to])
            /*
             * Si el día tuvo dos turnos, manda el último: es el que cierra
             * la caja. `keyBy` se queda con el que llega al final.
             */
            ->orderBy('sequence')
            ->get()
            ->keyBy(fn (CashCount $a): string => $a->counted_on->toDateString());
    }

    /**
     * Cuáles de esos cierres ya tienen su planilla emitida.
     *
     * En una sola consulta: preguntarlo por celda serían treinta consultas
     * para pintar treinta iconos.
     *
     * @param  list<int|string>  $closingIds
     * @return list<int>
     */
    private function sheets(array $closingIds): array
    {
        if ($closingIds === []) {
            return [];
        }

        $crudos = Attachment::query()
            ->where('subject_type', AttachmentSubject::PeriodClosing)
            ->whereIn('subject_id', $closingIds)
            ->distinct()
            ->pluck('subject_id');

        $ids = [];

        foreach ($crudos as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }
}
