<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ledger\Data\CashCountListItemData;
use App\Modules\Ledger\Data\PeriodClosingListItemData;
use App\Modules\Ledger\Enums\CashBoxAbility;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventStatus;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PeriodType;
use App\Modules\Ledger\Excel\CashSheetWorkbook;
use App\Modules\Ledger\Http\Controllers\Concerns\ResolvesCashBox;
use App\Modules\Ledger\Http\Controllers\Concerns\SelectsCurrency;
use App\Modules\Ledger\Models\CashCount;
use App\Modules\Ledger\Models\CashCountLine;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Ledger\Support\CashBankLedger;
use App\Modules\Ledger\Support\CashCalendar;
use App\Modules\Ledger\Support\CashDayActivity;
use App\Modules\Ledger\Support\CashDayTakings;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * El calendario de la caja.
 *
 * Es **otra forma de mirar lo mismo**, no otro dato: los cierres son los que
 * ya existen y el año es apenas navegación —no hay cierre anual—. Lo que la
 * lista no puede mostrar es lo que acá se ve primero: los días que tuvieron
 * movimientos y quedaron sin cerrar. Una lista enumera lo que existe, y en
 * un cierre de caja lo que importa es lo que falta.
 */
final class CashCalendarController extends Controller
{
    use ResolvesCashBox;
    use SelectsCurrency;

    public function __construct(
        private readonly CashCalendar $calendario,
        private readonly CashBalance $saldos,
        private readonly CashDayTakings $recaudacion,
        private readonly CashDayActivity $actividad,
        private readonly CashBankLedger $banco,
    ) {}

    public function index(Request $request): Response
    {
        $caja = $this->cashBox($request);
        $moneda = $this->selectedCurrency($request);

        $hoy = BusinessDate::today();

        /*
         * El día del que se viene, si alguien lo dijo. La caja del día manda
         * `?dia=AAAA-MM-DD` para que el calendario abra donde uno estaba y
         * lo marque: llegar a la grilla y tener que buscar el día que se
         * acaba de dejar es el trabajo que el enlace vino a evitar.
         *
         * Es **un solo parámetro y no tres**: el año y el mes salen de él,
         * así que no hay forma de pedir un 15 de junio dentro de julio.
         */
        $dia = $this->highlightedDay($request);

        $anio = $this->year($request, $hoy, $dia);

        /*
         * Sin mes en la dirección, la vista es la del año. Es el nivel al
         * que conviene entrar cuando se viene de otra pantalla: doce celdas
         * dicen dónde mirar, treinta obligan a saberlo de antes. Un día
         * marcado alcanza para elegir el mes, que es de donde sale.
         */
        $mes = $this->month($request, $dia);
        $modulo = $this->cashBoxModule($request);
        $rotuloDelBanco = $modulo->bankLedgerLabel();

        return Inertia::render('caja/calendario', [
            'selected' => [
                'cashBoxId' => (int) $caja->id,
                'cashBoxCode' => $caja->code,
                // Los libros que lleva la caja: el selector de moneda no ofrece otros.
                'currencies' => array_map(fn (Currency $c): string => $c->value, $this->currenciesOf($request)),
                'currency' => $moneda->value,
                'cashBoxName' => $caja->name,
                'year' => $anio,
                'month' => $mes,
                /* El día que llega marcado, para anillarlo en la grilla. */
                'day' => $dia?->toDateString(),
            ],
            'months' => $this->calendario->year((int) $caja->id, $anio, $moneda),
            'days' => $mes === null
                ? []
                : $this->calendario->month((int) $caja->id, $anio, $mes, $moneda),
            'monthlyClosing' => $mes === null
                ? null
                : $this->monthlyClosing((int) $caja->id, $anio, $mes, $moneda),
            /*
             * Lo que el libro de la caja reconoció en la cuenta, mes a mes y
             * en el año, si el módulo lo muestra: con una cuenta compartida
             * es lo único propio que se puede decir de ella.
             */
            'bankLedger' => $rotuloDelBanco === null ? null : [
                'label' => $rotuloDelBanco,
                'byMonth' => $porMes = $this->banco->byMonth((int) $caja->id, $anio, $moneda),
                'year' => array_reduce($porMes, fn (string $total, string $mes): string => Decimal::add($total, $mes), '0.00'),
            ],
            /* Lo que el módulo agrega al mes: para Multas, la rendición a la SAF. */
            'monthPanel' => $mes === null ? null : $modulo->monthPanel(
                CarbonImmutable::create($anio, $mes, 1),
                $this->monthlyClosingModel((int) $caja->id, $anio, $mes, $moneda),
                $request->user(),
            ),
            /*
             * El arqueo y el cierre del día elegido, las mismas tarjetas que
             * la caja del día. Solo se calculan cuando hay un día marcado
             * dentro del mes que se muestra: sin eso no hay tarjeta que
             * llenar, y la consulta no se paga.
             */
            'dayDetail' => $this->dayDetail((int) $caja->id, $moneda, $dia, $mes),
            'can' => [
                'count' => $this->canOnCashBox($request, CashBoxAbility::Count),
                'review' => $this->canOnCashBox($request, CashBoxAbility::ReviewCount),
                'adjust' => $this->canOnCashBox($request, CashBoxAbility::AdjustDifference),
                'close' => $this->canOnCashBox($request, CashBoxAbility::Close),
                'reopen' => $this->canOnCashBox($request, CashBoxAbility::Reopen),
                'export' => $this->canOnCashBox($request, CashBoxAbility::Export),
                'regenerate' => $this->canOnCashBox($request, CashBoxAbility::RegenerateSheet),
            ],
            /*
             * Lo que el panel del día necesita para operar y no solo
             * mirar: las denominaciones del arqueo y la versión del
             * dibujo de la planilla.
             */
            'suggestedDenominations' => CashCountLine::suggestedDenominations($moneda),
            'sheetVersion' => CashSheetWorkbook::VERSION,
            /*
             * Los días con movimientos sin cerrar, por mes. El diálogo de
             * cierre los usa para avisar antes de cerrar el mensual, y es
             * el mismo dato que ya arma la pantalla de Cierres: si cada
             * una lo calculara, el día que discrepen nadie sabría cuál
             * creer.
             */
            'pendingDaysByMonth' => $this->calendario->pendingDaysByMonth(
                (int) $caja->id,
                BusinessDate::today()->subMonths(13)->startOfMonth(),
                BusinessDate::today()->endOfMonth(),
                $moneda,
            ),
        ]);
    }

    /**
     * El arqueo y el cierre de un día, para las tarjetas de la derecha.
     *
     * Es lo mismo que arma la caja del día, leído desde acá para no mandar
     * al operador a otra pantalla a ver el detalle del día que ya tiene
     * elegido en la grilla. Solo cuando el día cae dentro del mes que se
     * muestra: un día sin mes es la vista del año, que no tiene panel.
     *
     * @return array{date: string, arqueos: list<array<string, mixed>>, previousCount: array<string, mixed>|null, compositionReference: array<string, mixed>|null, expectedCash: numeric-string, dayTakings: numeric-string, movedAfter: bool, closing: array<string, mixed>|null, activity: list<array<string, mixed>>}|null
     */
    private function dayDetail(int $cashBoxId, Currency $currency, ?CarbonImmutable $dia, ?int $mes): ?array
    {
        if ($dia === null || $mes === null || $dia->month !== $mes) {
            return null;
        }

        /*
         * Todos los turnos del día, no solo el último: el panel de la
         * derecha ofrece el mismo flujo que la caja del día, y ahí se
         * puede mirar la historia del conteo.
         */
        $arqueos = CashCount::query()
            ->with(['performedBy', 'reviewedBy', 'lines', 'carryLines'])
            ->where('cash_box_id', $cashBoxId)
            ->where('currency', $currency)
            ->whereDate('counted_on', $dia)
            ->orderBy('sequence')
            ->get();

        $cierre = PeriodClosing::query()
            ->with(['closedBy', 'reopenedBy', 'sheetAttachment', 'sheets.uploader'])
            ->where('cash_box_id', $cashBoxId)
            ->where('currency', $currency)
            ->where('period_type', PeriodType::Daily)
            ->whereDate('period_from', $dia)
            ->first();

        /*
         * Con un bucle y no con `map`: una clausura que devuelve `array`
         * pierde la forma de lista, y con ella la garantía de que la
         * pantalla recibe un arreglo indexado y no un mapa con huecos.
         */
        $turnos = [];

        foreach ($arqueos as $arqueo) {
            $turnos[] = CashCountListItemData::fromModel($arqueo)->toArray();
        }

        $anterior = CashCount::query()
            ->with(['performedBy', 'reviewedBy', 'lines', 'carryLines'])
            ->lastFirmBefore($cashBoxId, $currency, $dia)
            ->first();

        $referenciaDeComposicion = CashCount::query()
            ->with(['performedBy', 'reviewedBy', 'lines', 'carryLines'])
            ->lastFullCountBefore($cashBoxId, $currency, $dia)
            ->first();

        return [
            'date' => $dia->toDateString(),
            'activity' => $this->actividad->entries($cashBoxId, $dia, $currency),
            'arqueos' => $turnos,
            'previousCount' => $anterior === null
                ? null
                : CashCountListItemData::fromModel($anterior)->toArray(),
            'compositionReference' => $referenciaDeComposicion === null
                ? null
                : CashCountListItemData::fromModel($referenciaDeComposicion)->toArray(),
            /* Lo que el libro dice que hay ese día, para adelantar la diferencia. */
            'expectedCash' => $this->saldos->of(
                LedgerAccount::CashOnHand,
                $cashBoxId,
                $currency,
                $dia,
            ),
            'dayTakings' => $this->recaudacion->of($cashBoxId, $dia, $currency),
            'movedAfter' => FinancialEvent::query()
                ->where('cash_box_id', $cashBoxId)
                ->whereIn('status', [FinancialEventStatus::Posted, FinancialEventStatus::Reversed])
                ->whereDate('event_date', '>', $dia)
                ->exists(),
            'closing' => $cierre === null ? null : PeriodClosingListItemData::fromModel(
                $cierre,
                app(CashBalance::class)->of(LedgerAccount::CashOnHand, $cashBoxId, $currency, $cierre->period_to),
            )->toArray(),
        ];
    }

    private function monthlyClosing(
        int $cashBoxId,
        int $year,
        int $month,
        Currency $currency,
    ): ?PeriodClosingListItemData {
        $cierre = $this->monthlyClosingModel($cashBoxId, $year, $month, $currency);

        return $cierre === null ? null : PeriodClosingListItemData::fromModel($cierre);
    }

    private function monthlyClosingModel(int $cashBoxId, int $year, int $month, Currency $currency): ?PeriodClosing
    {
        return PeriodClosing::query()
            ->with(['closedBy', 'reopenedBy'])
            ->where('cash_box_id', $cashBoxId)
            ->where('currency', $currency)
            ->where('period_type', PeriodType::Monthly)
            ->whereDate('period_from', CarbonImmutable::create($year, $month, 1))
            ->first();
    }

    /** Un año razonable, y nunca uno que no existe todavía. */
    private function year(Request $request, CarbonImmutable $hoy, ?CarbonImmutable $dia): int
    {
        if ($request->query('anio') === null && $dia !== null) {
            return $dia->year;
        }

        $pedido = $request->integer('anio');

        if ($pedido < 2000 || $pedido > $hoy->year) {
            return $hoy->year;
        }

        return $pedido;
    }

    private function month(Request $request, ?CarbonImmutable $dia): ?int
    {
        if ($request->query('mes') === null) {
            // El mes del día marcado, o la vista del año si no hay ninguno.
            return $dia?->month;
        }

        $mes = $request->integer('mes');

        return $mes >= 1 && $mes <= 12 ? $mes : null;
    }

    /**
     * El día del que viene el operador, si la dirección lo dice.
     *
     * Una fecha ilegible se ignora en vez de romper la pantalla: es un
     * subrayado, no un dato del que dependa nada de lo que se muestra.
     */
    private function highlightedDay(Request $request): ?CarbonImmutable
    {
        $pedido = $request->query('dia');

        if (! is_string($pedido) || $pedido === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($pedido)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
