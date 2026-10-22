<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ledger\Actions\AdjustCashDifference;
use App\Modules\Ledger\Actions\RecordCashCount;
use App\Modules\Ledger\Actions\ReviewCashCount;
use App\Modules\Ledger\Data\CashCountListItemData;
use App\Modules\Ledger\Enums\CashBoxAbility;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Http\Controllers\Concerns\ResolvesCashBox;
use App\Modules\Ledger\Http\Controllers\Concerns\SelectsCurrency;
use App\Modules\Ledger\Http\Requests\RecordCashCountRequest;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Modules\Ledger\Models\CashCount;
use App\Support\BusinessDate;
use App\Support\Ui\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Arqueos de caja.
 *
 * El controlador valida, autoriza y delega: los cuatro actos —contar,
 * revisar, imputar la diferencia y el listado— viven en Actions, que es
 * donde el sistema exige que vivan las operaciones que tocan dinero.
 */
final class CashCountController extends Controller
{
    use ResolvesCashBox;
    use SelectsCurrency;

    public function index(Request $request): Response
    {
        $caja = $this->cashBox($request);
        $moneda = $this->selectedCurrency($request);
        $fechaPredeterminada = $this->selectedDate($request);

        $arqueos = CashCount::query()
            ->with(['performedBy', 'reviewedBy', 'lines', 'carryLines'])
            ->where('cash_box_id', $caja->id)
            ->where('currency', $moneda)
            ->orderByDesc('counted_on')
            ->orderByDesc('sequence')
            ->limit(120)
            ->get();

        return Inertia::render('caja/arqueos', [
            'selected' => [
                'cashBoxCode' => $caja->code,
                // Los libros que lleva la caja: el selector de moneda no ofrece otros.
                'currencies' => array_map(fn (Currency $c): string => $c->value, $this->currenciesOf($request)),
                'currency' => $moneda->value,
                'defaultDate' => $fechaPredeterminada->toDateString(),
            ],
            'counts' => $arqueos->map(CashCountListItemData::fromModel(...))->values()->all(),
            /* Sin apertura no hay contra qué contar: la pantalla lo dice en vez de listar. */
            'needsOpening' => CashBookOpening::for((int) $caja->id, $moneda) === null,
            'can' => [
                'review' => $this->canOnCashBox($request, CashBoxAbility::ReviewCount),
                'adjust' => $this->canOnCashBox($request, CashBoxAbility::AdjustDifference),
                'open' => $this->canOnCashBox($request, CashBoxAbility::Open),
            ],
        ]);
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

    public function store(RecordCashCountRequest $request, RecordCashCount $registrar): RedirectResponse
    {
        $this->assertCurrencyAllowed($request, Currency::from((string) $request->validated('currency')));

        $arqueo = $registrar->handle(
            cashBoxId: (int) $this->cashBox($request)->id,
            countedOn: CarbonImmutable::parse((string) $request->validated('countedOn')),
            denominations: $request->denominations(),
            currency: Currency::from((string) $request->validated('currency')),
            actorId: $request->user()?->id,
            explanation: $request->validated('explanation'),
            carryRecount: $request->carryRecount(),
        );

        return back()->with('status', sprintf(
            'Arqueo del %s registrado.',
            $arqueo->counted_on->format('d/m/Y'),
        ));
    }

    public function review(Request $request, CashCount $cashCount, ReviewCashCount $revisar): RedirectResponse
    {
        $revisar->handle($cashCount, (int) $request->user()?->id);

        return back()->with('status', 'Arqueo revisado.');
    }

    public function adjust(Request $request, CashCount $cashCount, AdjustCashDifference $imputar): RedirectResponse
    {
        $datos = $request->validate([
            'authorization' => ['nullable', 'string', 'min:5', 'max:300'],
        ]);

        $imputar->handle($cashCount, (int) $request->user()?->id, $datos['authorization'] ?? null);

        /*
         * No es un éxito liso: la caja quedó cuadrada porque se decidió
         * correr el libro, no porque el faltante apareciera.
         */
        Toast::warning(
            'Diferencia imputada a Diferencia de arqueo.',
            'El libro quedó igualado a lo contado y el día ya puede cerrarse.',
        );

        return back();
    }
}
