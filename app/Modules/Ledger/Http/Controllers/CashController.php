<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Data\CashBoxStateData;
use App\Modules\Ledger\Data\CashCountListItemData;
use App\Modules\Ledger\Data\PeriodClosingListItemData;
use App\Modules\Ledger\Enums\CashBoxAbility;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventStatus;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Excel\CashSheetWorkbook;
use App\Modules\Ledger\Http\Controllers\Concerns\ResolvesCashBox;
use App\Modules\Ledger\Http\Controllers\Concerns\SelectsCurrency;
use App\Modules\Ledger\Models\CashCount;
use App\Modules\Ledger\Models\CashCountLine;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Ledger\Support\CashDayActivity;
use App\Modules\Ledger\Support\CashDayBook;
use App\Modules\Ledger\Support\CashDayTakings;
use App\Modules\Shared\Models\CashBox;
use App\Support\BusinessDate;
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
    use ResolvesCashBox;
    use SelectsCurrency;

    public function __construct(
        private readonly CashBalance $saldos,
        private readonly CashDayBook $libro,
        private readonly CashDayTakings $recaudacion,
        private readonly CashDayActivity $actividad,
        private readonly RegisterOpeningBalance $abrir,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        return to_route('caja.dia', $request->query());
    }

    public function index(Request $request): Response
    {
        $caja = $this->cashBox($request);
        $moneda = $this->selectedCurrency($request);
        $fecha = $this->selectedDate($request);
        $libro = $this->libro->entries((int) $caja->id, $fecha, $fecha, $moneda);

        $arqueos = CashCount::query()
            ->with(['performedBy', 'reviewedBy', 'lines', 'carryLines'])
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

        $apertura = $this->abrir->existingFor((int) $caja->id, $moneda);

        return Inertia::render('caja/index', [
            'selected' => [
                'cashBoxId' => (int) $caja->id,
                'cashBoxCode' => $caja->code,
                // Los libros que lleva la caja: el selector de moneda no ofrece otros.
                'currencies' => array_map(fn (Currency $c): string => $c->value, $this->currenciesOf($request)),
                'currency' => $moneda->value,
                'date' => $fecha->toDateString(),
                'cashBoxName' => $caja->name,
            ],
            'state' => $this->state($caja, $moneda, $fecha),
            /*
             * Qué es el saldo «en la cuenta» cuando la cuenta es compartida:
             * lo que el libro de esta caja reconoció, no lo que dice el banco.
             */
            'bankColumnNote' => $this->cashBoxModule($request)->bankColumnNote(),
            /*
             * Lo que entró hoy y sigue en el cajón, para que el arqueo
             * pueda comparar el conteo contra algo que el operador no
             * eligió. El saldo del día anterior se sigue declarando.
             */
            'dayTakings' => $this->recaudacion->of((int) $caja->id, $fecha, $moneda),
            'book' => $libro,
            /*
             * A dónde lleva cada recibo del libro, si la caja lo dice: en
             * Multas, la ficha de la multa. Se resuelve al mirar y no se
             * congela, y en una sola consulta para todo el día.
             */
            'bookLinks' => (object) $this->cashBoxModule($request)->rowLinks(array_values(array_filter(
                array_column([...$libro['income'], ...$libro['expense']], 'receiptId'),
            )), $request->user()),
            'activity' => $this->actividad->entries((int) $caja->id, $fecha, $moneda),
            'counts' => $arqueos->map(CashCountListItemData::fromModel(...))->values()->all(),
            /*
             * El arqueo firme anterior, como referencia. No precarga el
             * conteo: sirve para copiar el fajo que no se recuenta y para
             * comparar al revisar.
             */
            'previousCount' => $this->previousCount((int) $caja->id, $moneda, $fecha),
            /* Última foto completa de los billetes, para comparar el recuento del fajo. */
            'compositionReference' => $this->compositionReference((int) $caja->id, $moneda, $fecha),
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
             * tienen los tres saldos iniciales.
             */
            'opening' => $apertura === null ? null : [
                'date' => $apertura->opened_on->toDateString(),
                'notes' => $apertura->notes,
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
                'open' => $this->canOnCashBox($request, CashBoxAbility::Open),
                'count' => $this->canOnCashBox($request, CashBoxAbility::Count),
                'review' => $this->canOnCashBox($request, CashBoxAbility::ReviewCount),
                'adjust' => $this->canOnCashBox($request, CashBoxAbility::AdjustDifference),
                'close' => $this->canOnCashBox($request, CashBoxAbility::Close),
                'reopen' => $this->canOnCashBox($request, CashBoxAbility::Reopen),
                'export' => $this->canOnCashBox($request, CashBoxAbility::Export),
                'regenerate' => $this->canOnCashBox($request, CashBoxAbility::RegenerateSheet),
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
            ->with(['performedBy', 'reviewedBy', 'lines', 'carryLines'])
            ->lastFirmBefore($cashBoxId, $moneda, $fecha)
            ->first();

        return $anterior === null ? null : CashCountListItemData::fromModel($anterior);
    }

    /** Último conteo completo con denominaciones, nunca un saldo calculado. */
    private function compositionReference(
        int $cashBoxId,
        Currency $moneda,
        CarbonImmutable $fecha,
    ): ?CashCountListItemData {
        $anterior = CashCount::query()
            ->with(['performedBy', 'reviewedBy', 'lines', 'carryLines'])
            ->lastFullCountBefore($cashBoxId, $moneda, $fecha)
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
     * El día que se está mirando.
     *
     * Hoy por defecto, y **nunca el futuro**: una caja de mañana no tiene
     * saldo que contar, y dejar navegar hacia adelante solo produce
     * pantallas vacías que parecen un error del sistema.
     */
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
}
