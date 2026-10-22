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
use App\Modules\Multas\Data\FinePendingPaymentData;
use App\Modules\Multas\Enums\FineCollectionPlace;
use App\Modules\Multas\Http\Requests\OpenFineCashBookRequest;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Support\FineChequeInventory;
use App\Modules\Multas\Support\FineDeposits;
use App\Modules\Shared\Models\CashBox;
use App\Support\Money\Decimal;
use App\Support\Ui\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La caja de Multas: su apertura, la cuenta con la que opera y lo cobrado
 * camino al banco.
 *
 * Antes de abrir, el formulario; después, lo declarado y la cuenta en dos
 * números separados —el saldo del banco, compartido con Aranceles, y lo
 * cobrado por Multas— que no se suman nunca, y los cobros por depositar y
 * en tránsito. Arqueos y cierres llegan en la Etapa 6.
 */
final class FineCashController extends Controller
{
    public function __construct(
        private readonly OpeningLines $lineas,
        private readonly AccountCutoffBalance $saldos,
        private readonly FineDeposits $depositos,
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
            /*
             * Lo cobrado que sigue en la caja y lo depositado que espera al
             * extracto (M06). Cada cobro va entero y solo, a la cuenta del libro.
             */
            'paraDepositar' => $apertura === null ? [] : $this->cobros(FineCollectionPlace::InCustody),
            'enTransito' => $apertura === null ? [] : $this->cobros(FineCollectionPlace::InTransit),
            // Los cheques rechazados con algo pendiente: empresa, banco o papeles (M03).
            'rechazados' => $apertura === null ? [] : $this->filas($this->depositos->paymentsWithPendingRejections()),
            'canManageRejections' => $request->user()?->can('multas.gestionar-rechazos') ?? false,
            'canReplaceCheque' => $request->user()?->can('multas.cobrar') ?? false,
            'canDeposit' => ($request->user()?->can('multas.depositar') ?? false) && $apertura?->bank_account_id !== null,
            'canConfirmDeposit' => $request->user()?->can('multas.verificar-pagos') ?? false,
            'canViewAttachments' => $request->user()?->can('adjuntos.ver') ?? false,
            'canViewCashDay' => $request->user()?->can('multas.caja.ver') ?? false,
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

    /**
     * Los cobros de la caja en ese lugar, del más viejo al más nuevo: es lo
     * que lleva más tiempo esperando.
     *
     * @return list<FinePendingPaymentData>
     */
    private function cobros(FineCollectionPlace $lugar): array
    {
        return $this->filas($this->depositos->counterPayments($lugar));
    }

    /**
     * @param  Builder<FinePayment>  $consulta
     * @return list<FinePendingPaymentData>
     */
    private function filas(Builder $consulta): array
    {
        $pagos = $consulta
            ->with(['fine.fineCase', 'application.fundReceipt', 'paymentReceipt.receipt', 'creator:id,name'])
            ->orderBy('id')
            ->get();

        $depositos = $this->depositos->forPayments($pagos);

        return array_values($pagos
            ->map(fn (FinePayment $pago): FinePendingPaymentData => FinePendingPaymentData::fromModel($pago, $depositos[$pago->id] ?? null))
            ->all());
    }

    private function cashBox(): CashBox
    {
        return CashBox::query()->active()->where('code', CashBox::MULTAS)->firstOrFail();
    }
}
