<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankAccountOpening;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Support\AccountCutoffBalance;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Modules\Ledger\Models\CashCountLine;
use App\Modules\Ledger\Support\OpeningLines;
use App\Modules\Multas\Actions\OpenFineCashBook;
use App\Modules\Multas\Data\FineBankPanelData;
use App\Modules\Multas\Http\Requests\OpenFineCashBookRequest;
use App\Modules\Shared\Models\CashBox;
use App\Support\Money\Decimal;
use App\Support\Ui\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La caja de Multas: su apertura y la cuenta con la que opera.
 *
 * Antes de abrir, el formulario; después, lo declarado y la cuenta en dos
 * números separados —el saldo del banco, compartido con Aranceles, y lo
 * cobrado por Multas— que no se suman nunca. Arqueos y cierres llegan en
 * la Etapa 6.
 */
final class FineCashController extends Controller
{
    public function __construct(
        private readonly OpeningLines $lineas,
        private readonly AccountCutoffBalance $saldos,
    ) {}

    public function index(Request $request): Response
    {
        $caja = $this->cashBox();
        $apertura = CashBookOpening::for((int) $caja->id, Currency::Ars);

        return Inertia::render('multas/caja', [
            'opening' => $apertura === null ? null : [
                'date' => $apertura->opened_on->toDateString(),
                'postedAt' => $apertura->created_at->toIso8601String(),
                'description' => $apertura->notes,
                'isEmpty' => $apertura->isEmpty(),
                'lines' => $this->lineas->of((int) $caja->id, Currency::Ars),
            ],
            'bank' => $apertura?->bank_account_id === null ? null : $this->bankPanel($caja, $apertura),
            'bankAccounts' => $apertura === null ? $this->bankAccounts() : [],
            'suggestedDenominations' => CashCountLine::suggestedDenominations(Currency::Ars),
            'canOpen' => $request->user()?->can('multas.caja.abrir') ?? false,
        ]);
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

        return to_route('multas.caja.index');
    }

    /**
     * Las cuentas en pesos que la caja puede declarar, con su corte.
     *
     * El corte se muestra para sugerir la fecha: la caja abre después.
     *
     * @return list<array{id: int, label: string, cutoffOn: string|null}>
     */
    private function bankAccounts(): array
    {
        $cortes = BankAccountOpening::query()->pluck('cutoff_on', 'bank_account_id');

        return array_values(BankAccount::query()
            ->where('is_active', true)
            ->where('currency', Currency::Ars->value)
            ->orderBy('label')
            ->get()
            ->map(fn (BankAccount $cuenta): array => [
                'id' => $cuenta->id,
                'label' => $cuenta->label,
                'cutoffOn' => isset($cortes[$cuenta->id])
                    ? CarbonImmutable::parse((string) $cortes[$cuenta->id])->toDateString()
                    : null,
            ])
            ->all());
    }

    private function bankPanel(CashBox $caja, CashBookOpening $apertura): FineBankPanelData
    {
        $cuenta = BankAccount::query()->findOrFail($apertura->bank_account_id);
        $corte = BankAccountOpening::query()->where('bank_account_id', $cuenta->id)->first();
        $ultimo = $this->saldos->latest($cuenta->id);

        $enElLibro = DB::table('journal_lines')
            ->join('financial_events', 'financial_events.id', '=', 'journal_lines.financial_event_id')
            ->where('journal_lines.cash_box_id', $caja->id)
            ->where('journal_lines.bank_account_id', $cuenta->id)
            ->where('journal_lines.account_code', LedgerAccount::BankAccount->value)
            ->whereIn('financial_events.status', ['posted', 'reversed'])
            ->selectRaw('COALESCE(SUM(journal_lines.debit - journal_lines.credit), 0) AS saldo')
            ->value('saldo');

        /*
         * Lo que entró después del corte —o de la apertura, si la cuenta
         * todavía no tiene corte— y nadie identificó: es la cola de
         * «pendiente de clasificación de caja» (M08), sin tabla propia. Un
         * crédito que ya respalda un pago anterior vigente está identificado
         * aunque no tenga imputación en el libro; si ese pago se descarta,
         * vuelve a la cola.
         */
        $desde = $corte?->cutoff_on->addDay() ?? $apertura->opened_on;

        $pendientes = BankTransaction::query()
            ->where('bank_account_id', $cuenta->id)
            ->where('direction', TransactionDirection::Credit->value)
            ->where('reconciliation_status', ReconciliationStatus::Pending->value)
            ->where('transaction_date', '>=', $desde->toDateString())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('fine_historical_movements')
                ->whereColumn('fine_historical_movements.bank_transaction_id', 'bank_transactions.id')
                ->where('fine_historical_movements.status', '<>', 'rejected'))
            ->selectRaw('COUNT(*) AS cantidad, COALESCE(SUM(amount), 0) AS total')
            ->first();

        return new FineBankPanelData(
            bankAccountId: $cuenta->id,
            bankAccountLabel: $cuenta->label,
            cutoffOn: $corte?->cutoff_on->toDateString(),
            cutoffBalance: $corte?->statement_balance,
            latestStatementBalance: $ultimo['balance'] ?? null,
            latestStatementDate: $ultimo['date'] ?? null,
            bookBalance: Decimal::scale((string) ($enElLibro ?? '0')),
            unclassifiedCount: (int) ($pendientes?->getAttribute('cantidad') ?? 0),
            unclassifiedAmount: Decimal::scale((string) ($pendientes?->getAttribute('total') ?? '0')),
        );
    }

    private function cashBox(): CashBox
    {
        return CashBox::query()->active()->where('code', CashBox::MULTAS)->firstOrFail();
    }
}
