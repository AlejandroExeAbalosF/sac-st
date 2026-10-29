<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Modules\Ledger\Models\CashCountLine;
use App\Modules\Ledger\Support\OpeningLines;
use App\Modules\Multas\Actions\OpenFineCashBook;
use App\Modules\Multas\Http\Requests\OpenFineCashBookRequest;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Support\FineChequeInventory;
use App\Modules\Shared\Models\CashBox;
use App\Support\Ui\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La caja de Multas fuera de las pantallas de caja de Ledger: su apertura,
 * la cartera de cheques para el modal del día y la búsqueda de multas para
 * identificar un crédito.
 *
 * La apertura va una sola vez, con la cuenta con la que opera y sin saldo
 * bancario: la cuenta es compartida con Aranceles. Después, la pantalla
 * muestra lo declarado; el día a día está en la caja del día.
 */
final class FineCashController extends Controller
{
    public function __construct(private readonly OpeningLines $lineas) {}

    public function apertura(Request $request): Response
    {
        $caja = $this->cashBox();
        $apertura = CashBookOpening::for((int) $caja->id, Currency::Ars);

        return Inertia::render('multas/apertura', [
            'opening' => $apertura === null ? null : [
                'date' => $apertura->opened_on->toDateString(),
                'postedAt' => $apertura->created_at->toIso8601String(),
                'description' => $apertura->notes,
                'isEmpty' => $apertura->isEmpty(),
                'lines' => $this->lineas->of((int) $caja->id, Currency::Ars),
            ],
            'bankAccountLabel' => $apertura?->bank_account_id === null
                ? null
                : BankAccount::query()->whereKey($apertura->bank_account_id)->value('label'),
            'bankAccounts' => $apertura === null ? $this->bankAccounts() : [],
            'suggestedDenominations' => CashCountLine::suggestedDenominations(Currency::Ars),
            'canOpen' => $request->user()?->can('multas.caja.abrir') ?? false,
        ]);
    }

    /**
     * La cartera de cheques de la caja, para el modal de la caja del día.
     * Responde JSON: se abre encima de la caja del día, sin dejarla.
     */
    public function cheques(Request $request, FineChequeInventory $cartera): JsonResponse
    {
        return response()->json($cartera->for(
            (int) $this->cashBox()->id,
            // Quien solo opera la caja ve de qué multa es cada cheque, sin enlace.
            canOpenFines: $request->user()?->can('multas.ver') ?? false,
        ));
    }

    /**
     * Las multas que coinciden con lo que se busca, para identificar un
     * crédito que nadie informó: expediente, resolución, acta o carátula.
     */
    public function fines(Request $request): JsonResponse
    {
        $termino = trim((string) $request->query('q', ''));

        if (mb_strlen($termino) < 2) {
            return response()->json([]);
        }

        return response()->json(Fine::query()
            ->buscar($termino)
            ->with(['fineCase', 'balance'])
            ->orderByDesc('id')
            ->limit(15)
            ->get()
            ->map(fn (Fine $multa): array => [
                'id' => $multa->id,
                'caseLabel' => $multa->fineCase->label(),
                'caption' => $multa->caption,
                'debt' => $multa->balance->debt ?? '0.00',
            ])
            ->values());
    }

    public function store(OpenFineCashBookRequest $request, OpenFineCashBook $abrir): RedirectResponse
    {
        $abrir->handle(
            date: CarbonImmutable::parse((string) $request->validated('date')),
            bankAccountId: (int) $request->validated('bankAccountId'),
            balances: $request->balances(),
            denominations: $request->denominations(),
            cheques: $request->cheques(),
            notes: $request->validated('notes'),
            declaredEmpty: $request->boolean('declaredEmpty'),
            actorId: (int) $request->user()?->id,
        );

        Toast::success('Caja de Multas abierta con el saldo declarado.');

        return to_route('multas.caja.dia', ['fecha' => (string) $request->validated('date')]);
    }

    /**
     * Las cuentas en pesos con las que la caja puede operar.
     *
     * @return list<array{id: int, label: string}>
     */
    private function bankAccounts(): array
    {
        return array_values(BankAccount::query()
            ->where('is_active', true)
            ->where('currency', Currency::Ars->value)
            ->orderBy('label')
            ->get()
            ->map(fn (BankAccount $cuenta): array => ['id' => $cuenta->id, 'label' => $cuenta->label])
            ->all());
    }

    private function cashBox(): CashBox
    {
        return CashBox::query()->active()->where('code', CashBox::MULTAS)->firstOrFail();
    }
}
