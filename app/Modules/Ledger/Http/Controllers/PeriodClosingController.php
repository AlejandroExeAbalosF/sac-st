<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ledger\Actions\ClosePeriod;
use App\Modules\Ledger\Actions\ExportCashSheet;
use App\Modules\Ledger\Actions\RegenerateCashSheet;
use App\Modules\Ledger\Actions\ReopenPeriod;
use App\Modules\Ledger\Data\PeriodClosingListItemData;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PeriodType;
use App\Modules\Ledger\Excel\CashSheetWorkbook;
use App\Modules\Ledger\Http\Controllers\Concerns\SelectsCurrency;
use App\Modules\Ledger\Http\Requests\RegenerateCashSheetRequest;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Ledger\Support\CashCalendar;
use App\Modules\Shared\Models\CashBox;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use App\Support\Ui\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Cierres de período.
 *
 * Cerrar congela los totales del día y traba las operaciones retroactivas;
 * reabrir es la única salida y deja motivo. Las dos cosas las hace el
 * contador, y el permiso lo dice antes que el código.
 */
final class PeriodClosingController extends Controller
{
    use SelectsCurrency;

    public function __construct(
        private readonly CashCalendar $calendario,
        private readonly CashBalance $cashBalance,
    ) {}

    public function index(Request $request): Response
    {
        $caja = $this->cashBox();
        $moneda = $this->selectedCurrency($request);
        $fechaPredeterminada = $this->selectedDate($request);

        $cierres = PeriodClosing::query()
            ->with(['closedBy', 'reopenedBy', 'cashBox', 'sheetAttachment', 'sheets.uploader'])
            ->where('cash_box_id', $caja->id)
            ->where('currency', $moneda)
            ->orderByDesc('period_to')
            ->orderBy('period_type')
            ->limit(120)
            ->get();

        $usuario = $request->user();

        /*
         * El acumulado del libro día por día, una sola vez: comparar
         * ciento veinte cierres llamando al saldo de cada uno serían
         * ciento veinte consultas.
         */
        $acumulado = $this->cashBalance->runningTotals(
            LedgerAccount::CashOnHand,
            (int) $caja->id,
            $moneda,
        );

        return Inertia::render('caja/cierres', [
            'selected' => [
                'cashBoxId' => (int) $caja->id,
                'cashBoxName' => $caja->name,
                'currency' => $moneda->value,
                'defaultDate' => $fechaPredeterminada->toDateString(),
            ],
            /*
             * Cuántos días con movimientos quedaron sin cerrar en cada mes,
             * para que el diálogo lo avise al elegir el período. Es un aviso
             * y no un bloqueo: el DER no lo pide y los totales del mes no
             * dependen de los cierres diarios. Lo que evita es cerrar un mes
             * cuyo libro va a salir sin esas hojas.
             */
            'pendingDaysByMonth' => $this->calendario->pendingDaysByMonth(
                (int) $caja->id,
                BusinessDate::today()->subMonths(13)->startOfMonth(),
                BusinessDate::today()->endOfMonth(),
                $moneda,
            ),
            'closings' => $cierres
                ->map(fn (PeriodClosing $cierre): PeriodClosingListItemData => PeriodClosingListItemData::fromModel(
                    $cierre,
                    CashBalance::balanceOn($acumulado, $cierre->period_to->toDateString()),
                ))
                ->values()
                ->all(),
            'can' => [
                'viewDay' => $usuario?->can('caja.ver') ?? false,
                'close' => $usuario?->can('cierres.cerrar') ?? false,
                'reopen' => $usuario?->can('cierres.reabrir') ?? false,
                'export' => $usuario?->can('cierres.exportar') ?? false,
                'regenerate' => $usuario?->can('cierres.regenerar-planilla') ?? false,
            ],
            /*
             * La versión del dibujo que produce el generador de hoy. La
             * pantalla la compara contra la de cada planilla guardada: sin
             * este número, una planilla vieja se ve igual de oficial que
             * una al día.
             */
            'sheetVersion' => CashSheetWorkbook::VERSION,
        ]);
    }

    /**
     * La caja de Haberes.
     *
     * `cash_boxes` tiene tres filas porque el motor contable lleva esa
     * dimensión desde la Etapa 2, pero hoy solo Haberes tiene circuito:
     * ofrecer un selector sería poner dos opciones apagadas en la pantalla.
     */
    private function cashBox(): CashBox
    {
        return CashBox::query()->active()->where('code', CashBox::HABERES)->firstOrFail();
    }

    /** La fecha desde la que se llegó, limitada a la jornada actual. */
    private function selectedDate(Request $request): CarbonImmutable
    {
        $hoy = BusinessDate::today();
        $pedida = $request->query('fecha');

        if (! is_string($pedida) || $pedida === '') {
            return $hoy;
        }

        try {
            $fecha = CarbonImmutable::parse($pedida)->startOfDay();
        } catch (Throwable) {
            return $hoy;
        }

        return $fecha->greaterThan($hoy) ? $hoy : $fecha;
    }

    public function store(Request $request, ClosePeriod $cerrar): RedirectResponse
    {
        $datos = $request->validate([
            'cashBoxId' => ['required', 'integer', Rule::exists('cash_boxes', 'id')->where('is_active', true)],
            'date' => ['required', 'date', 'before_or_equal:'.BusinessDate::today()->toDateString()],
            'periodType' => ['required', Rule::enum(PeriodType::class)],
            'currency' => ['required', Rule::enum(Currency::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            // Que el período haya terminado lo comprueba `ClosePeriod`: para el
            // mensual no alcanza con que la fecha no sea futura.
            'date.before_or_equal' => 'No se puede cerrar con una fecha que todavía no llegó.',
        ]);

        $cierre = $cerrar->handle(
            cashBoxId: (int) $this->cashBox()->id,
            date: CarbonImmutable::parse((string) $datos['date']),
            type: PeriodType::from((string) $datos['periodType']),
            currency: Currency::from((string) $datos['currency']),
            actorId: $request->user()?->id,
            notes: $datos['notes'] ?? null,
        );

        Toast::success(
            sprintf(
                'Período %s cerrado.',
                $cierre->period_from->equalTo($cierre->period_to)
                    ? 'del '.$cierre->period_from->format('d/m/Y')
                    : "del {$cierre->period_from->format('d/m/Y')} al {$cierre->period_to->format('d/m/Y')}",
            ),
            sprintf('Saldo final $ %s en efectivo.', Decimal::format($cierre->closing_cash)),
        );

        return back();
    }

    public function reopen(Request $request, PeriodClosing $closing, ReopenPeriod $reabrir): RedirectResponse
    {
        $datos = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'reason.required' => 'Reabrir un período cerrado exige decir por qué.',
            'reason.min' => 'El motivo tiene que explicar algo: cinco caracteres no alcanzan.',
        ]);

        $reabrir->handle($closing, (int) $request->user()?->id, $datos['reason']);

        Toast::success(
            'Período reabierto.',
            'Las operaciones con fecha adentro vuelven a admitirse.',
        );

        return back();
    }

    /**
     * Emite la planilla y lleva a su descarga.
     *
     * No devuelve el archivo directo: lo guarda como adjunto y redirige a
     * la única puerta que sirve archivos, que es la que comprueba permisos.
     * Si el cierre ya tenía su planilla, el Action devuelve esa — el papel
     * firmado y el archivo del sistema tienen que ser el mismo objeto.
     */
    public function sheet(Request $request, PeriodClosing $closing, ExportCashSheet $emitir): RedirectResponse
    {
        $adjunto = $emitir->handle($closing, $request->user()?->id);

        return redirect()->route('adjuntos.download', ['attachment' => $adjunto->id]);
    }

    /**
     * Rehacer la planilla cuando cambió el dibujo, no los números.
     *
     * Vuelve a la lista en vez de bajar el archivo: quien rehace una
     * planilla está corrigiendo el documento del cierre, no pidiéndolo
     * para leerlo. El enlace de descarga sigue donde estaba y ahora
     * entrega la versión nueva.
     */
    public function regenerateSheet(
        RegenerateCashSheetRequest $request,
        PeriodClosing $closing,
        RegenerateCashSheet $rehacer,
    ): RedirectResponse {
        $rehacer->handle(
            $closing,
            (int) $request->user()?->id,
            (string) $request->validated('reason'),
        );

        Toast::success('La planilla se rehízo.', 'La anterior queda archivada.');

        return back();
    }
}
