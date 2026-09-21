<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Data\CashBoxStateData;
use App\Modules\Ledger\Data\CashCountListItemData;
use App\Modules\Ledger\Data\PeriodClosingListItemData;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventStatus;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Excel\CashSheetWorkbook;
use App\Modules\Ledger\Http\Controllers\Concerns\SelectsCurrency;
use App\Modules\Ledger\Models\CashCount;
use App\Modules\Ledger\Models\CashCountLine;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Ledger\Support\CashDayActivity;
use App\Modules\Ledger\Support\CashDayBook;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * La caja del día.
 *
 * Cierra el recorrido del dinero que la barra lateral describe: el
 * expediente entra, el dinero se recibe, se identifica contra el banco, se
 * paga — y acá se cuenta y se cierra.
 *
 * La pantalla responde una sola pregunta en la parte de arriba —**cuánto
 * hay y dónde**— y abajo muestra de dónde salió ese número. Es el mismo
 * orden que tiene la planilla de papel, y no es casual: el área ya sabe
 * leerla en ese orden.
 */
final class CashController extends Controller
{
    use SelectsCurrency;

    public function __construct(
        private readonly CashBalance $saldos,
        private readonly CashDayBook $libro,
        private readonly CashDayActivity $actividad,
        private readonly RegisterOpeningBalance $abrir,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        return to_route('caja.dia', $request->query());
    }

    public function index(Request $request): Response
    {
        $caja = $this->cashBox();
        $moneda = $this->selectedCurrency($request);
        $fecha = $this->selectedDate($request);

        $arqueos = CashCount::query()
            ->with(['performedBy', 'reviewedBy', 'lines'])
            ->where('cash_box_id', $caja->id)
            ->where('currency', $moneda)
            ->whereDate('counted_on', $fecha)
            ->orderBy('sequence')
            ->get();

        $cierre = PeriodClosing::query()
            ->with(['closedBy', 'reopenedBy', 'sheetAttachment', 'sheets.uploader'])
            ->where('cash_box_id', $caja->id)
            ->where('currency', $moneda)
            ->where('period_type', 'daily')
            ->whereDate('period_from', $fecha)
            ->first();

        $usuario = $request->user();
        $apertura = $this->abrir->existingFor((int) $caja->id, $moneda);

        return Inertia::render('caja/index', [
            'selected' => [
                'cashBoxId' => (int) $caja->id,
                'currency' => $moneda->value,
                'date' => $fecha->toDateString(),
                'cashBoxName' => $caja->name,
            ],
            'state' => $this->state($caja, $moneda, $fecha),
            'book' => $this->libro->entries((int) $caja->id, $fecha, $fecha, $moneda),
            'activity' => $this->actividad->entries((int) $caja->id, $fecha, $moneda),
            'counts' => $arqueos->map(CashCountListItemData::fromModel(...))->values()->all(),
            /*
             * El arqueo firme anterior, como referencia. No precarga el
             * conteo: sirve para copiar el fajo que no se recuenta y para
             * comparar al revisar.
             */
            'previousCount' => $this->previousCount((int) $caja->id, $moneda, $fecha),
            /*
             * Si la caja se movió después del día que se está mirando.
             *
             * Contar hoy el cajón y fecharlo ayer solo es exacto si entre
             * medio no entró ni salió nada: el arqueo declara qué había al
             * cierre de ese día, y el cajón de ahora ya incluiría lo
             * posterior.
             */
            'movedAfter' => $this->movedAfter((int) $caja->id, $fecha),
            'closing' => $cierre === null ? null : PeriodClosingListItemData::fromModel(
                $cierre,
                $this->saldos->of(LedgerAccount::CashOnHand, (int) $caja->id, $moneda, $cierre->period_to),
            ),
            /*
             * Si los libros todavía no se abrieron, es lo único que importa
             * de esta pantalla: todos los saldos son cero y el primer arqueo
             * daría una diferencia igual a todo el saldo histórico.
             */
            'needsOpening' => $apertura === null,
            /*
             * La apertura, para poder mostrar de dónde salió el saldo con
             * el que arrancó todo. Su observación —«según acta de arqueo
             * del 31/05», «planilla manual»— es la única explicación que
             * tienen los tres saldos iniciales, y hasta ahora vivía
             * enterrada en la pantalla de apertura.
             */
            'opening' => $apertura === null ? null : [
                'date' => $apertura->event_date->toDateString(),
                'notes' => $apertura->description,
            ],
            /*
             * Lo que queda del sistema anterior. Aparece mientras haya algo
             * y desaparece solo el día que llegue a cero — que es cuando se
             * puede apagar la planilla en paralelo.
             */
            'legacyPending' => $this->saldos->of(LedgerAccount::LegacyFunds, (int) $caja->id, $moneda),
            /*
             * Las denominaciones que el área usa, para que el arqueo se
             * pueda cargar desde acá sin ir a la otra pantalla: la jornada
             * termina en esta caja, no en la lista de arqueos.
             */
            'suggestedDenominations' => CashCountLine::suggestedDenominations($moneda),
            'can' => [
                'open' => $usuario?->can('caja.abrir-saldo-inicial') ?? false,
                'payLegacy' => $usuario?->can('caja.pagar-anterior') ?? false,
                'count' => $usuario?->can('caja.arquear') ?? false,
                'review' => $usuario?->can('caja.revisar-arqueo') ?? false,
                'adjust' => $usuario?->can('caja.ajustar-diferencia') ?? false,
                'close' => $usuario?->can('cierres.cerrar') ?? false,
                'reopen' => $usuario?->can('cierres.reabrir') ?? false,
                'export' => $usuario?->can('cierres.exportar') ?? false,
                'regenerate' => $usuario?->can('cierres.regenerar-planilla') ?? false,
            ],
            /*
             * La versión del dibujo que produce el generador de hoy, para
             * que la tarjeta del cierre pueda avisar si la planilla
             * guardada quedó atrás sin mandar a nadie a otra pantalla.
             */
            'sheetVersion' => CashSheetWorkbook::VERSION,
        ]);
    }

    /** Si hubo movimientos posteriores al día que se está mirando. */
    private function movedAfter(int $cashBoxId, CarbonImmutable $fecha): bool
    {
        return FinancialEvent::query()
            ->where('cash_box_id', $cashBoxId)
            ->whereIn('status', [FinancialEventStatus::Posted, FinancialEventStatus::Reversed])
            ->whereDate('event_date', '>', $fecha)
            ->exists();
    }

    /** El último arqueo firme anterior al día que se está mirando. */
    private function previousCount(int $cashBoxId, Currency $moneda, CarbonImmutable $fecha): ?CashCountListItemData
    {
        $anterior = CashCount::query()
            ->with(['performedBy', 'reviewedBy', 'lines'])
            ->lastFirmBefore($cashBoxId, $moneda, $fecha)
            ->first();

        return $anterior === null ? null : CashCountListItemData::fromModel($anterior);
    }

    private function state(CashBox $caja, Currency $moneda, CarbonImmutable $fecha): CashBoxStateData
    {
        $id = (int) $caja->id;

        return new CashBoxStateData(
            cashBoxId: $id,
            code: $caja->code,
            name: $caja->name,
            currency: $moneda->value,
            cash: $this->saldos->of(LedgerAccount::CashOnHand, $id, $moneda, $fecha),
            cheques: $this->saldos->of(LedgerAccount::ChequesInCustody, $id, $moneda, $fecha),
            bank: $this->saldos->of(LedgerAccount::BankAccount, $id, $moneda, $fecha),
            unassigned: $this->saldos->of(LedgerAccount::UnassignedFunds, $id, $moneda, $fecha),
            inTransit: $this->saldos->of(LedgerAccount::CashInTransit, $id, $moneda, $fecha),
        );
    }

    /**
     * La caja de Haberes.
     *
     * `cash_boxes` tiene tres filas —Haberes, Aranceles, Multas— porque el
     * motor contable lleva esa dimensión desde la Etapa 2 y es lo que
     * permitirá reutilizarlo. Pero **hoy solo Haberes tiene circuito**, así
     * que ofrecer un selector sería poner dos opciones apagadas en cada
     * pantalla. El día que otra caja opere, el selector vuelve; mientras
     * tanto la dimensión vive en los datos y no en la interfaz.
     */
    private function cashBox(): CashBox
    {
        return CashBox::query()->active()->where('code', CashBox::HABERES)->firstOrFail();
    }

    /**
     * El día que se está mirando.
     *
     * Hoy por defecto, y **nunca el futuro**: una caja de mañana no tiene
     * saldo que contar, y dejar navegar hacia adelante solo produce
     * pantallas vacías que parecen un error del sistema.
     */
    private function selectedDate(Request $request): CarbonImmutable
    {
        $hoy = CarbonImmutable::now()->startOfDay();
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
}
