<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Aranceles\Actions\OpenFeeCashBook;
use App\Modules\Aranceles\Http\Requests\OpenFeeCashBookRequest;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Modules\Ledger\Models\CashCountLine;
use App\Modules\Ledger\Support\OpeningLines;
use App\Modules\Shared\Models\CashBox;
use App\Support\Ui\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** La apertura de la caja de Aranceles: una sola vez, sin saldo bancario. */
final class FeeCashController extends Controller
{
    public function __construct(private readonly OpeningLines $lineas) {}

    public function apertura(Request $request): Response
    {
        $caja = (int) CashBox::query()->active()->where('code', CashBox::ARANCELES)->firstOrFail()->id;
        $apertura = CashBookOpening::for($caja, Currency::Ars);

        return Inertia::render('aranceles/apertura', [
            'opening' => $apertura === null ? null : [
                'date' => $apertura->opened_on->toDateString(),
                'postedAt' => $apertura->created_at->toIso8601String(),
                'description' => $apertura->notes,
                'isEmpty' => $apertura->isEmpty(),
                'lines' => $this->lineas->of($caja, Currency::Ars),
            ],
            'bankAccountLabel' => $apertura?->bank_account_id === null
                ? null
                : BankAccount::query()->whereKey($apertura->bank_account_id)->value('label'),
            'bankAccounts' => $apertura !== null ? [] : array_values(BankAccount::query()
                ->where('is_active', true)
                ->where('currency', Currency::Ars->value)
                ->orderBy('label')
                ->get()
                ->map(fn (BankAccount $cuenta): array => ['id' => $cuenta->id, 'label' => $cuenta->label])
                ->all()),
            'suggestedDenominations' => CashCountLine::suggestedDenominations(Currency::Ars),
            'canOpen' => $request->user()?->can('aranceles.caja.abrir') ?? false,
        ]);
    }

    public function store(OpenFeeCashBookRequest $request, OpenFeeCashBook $abrir): RedirectResponse
    {
        $abrir->handle(
            date: CarbonImmutable::parse((string) $request->validated('date')),
            bankAccountId: (int) $request->validated('bankAccountId'),
            balances: $request->balances(),
            denominations: $request->denominations(),
            notes: $request->validated('notes'),
            declaredEmpty: $request->boolean('declaredEmpty'),
            actorId: (int) $request->user()?->id,
        );

        Toast::success('Caja de Aranceles abierta con el saldo declarado.');

        return to_route('aranceles.caja.dia', ['fecha' => (string) $request->validated('date')]);
    }
}
