<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Support\LatestStatementBalance;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Modules\Ledger\Models\CashCountLine;
use App\Modules\Ledger\Support\OpeningLines;
use App\Modules\Multas\Actions\OpenFineCashBook;
use App\Modules\Multas\Data\FineBankCreditData;
use App\Modules\Multas\Data\FineBankPanelData;
use App\Modules\Multas\Data\FinePendingPaymentData;
use App\Modules\Multas\Data\FineUnidentifiedCreditData;
use App\Modules\Multas\Enums\FineCollectionPlace;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Http\Requests\OpenFineCashBookRequest;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Support\FineBankCredits;
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
 * reconocido en el libro de Multas— que no se suman nunca, y los cobros por depositar y
 * en tránsito. Arqueos y cierres llegan en la Etapa 6.
 */
final class FineCashController extends Controller
{
    /** Cuántos créditos sin identificar muestra cada página de la cola. */
    private const COLA = 50;

    public function __construct(
        private readonly OpeningLines $lineas,
        private readonly LatestStatementBalance $saldos,
        private readonly FineDeposits $depositos,
        private readonly FineBankCredits $creditos,
    ) {}

    public function index(Request $request): Response
    {
        $caja = $this->cashBox();
        $apertura = CashBookOpening::for((int) $caja->id, Currency::Ars);
        $identifica = ($request->user()?->can('multas.verificar-pagos') ?? false) && $apertura?->bank_account_id !== null;

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
            /*
             * Los créditos de la cuenta compartida que nadie identificó (M08),
             * del más viejo al más nuevo. Identificar es de quien concilia;
             * mirar la cola, de cualquiera que mire la caja.
             */
            /*
             * Por páginas: los de Aranceles se quedan en la cola, y sin
             * recorrerla entera los de atrás nunca se alcanzarían.
             */
            'creditos' => $apertura?->bank_account_id === null
                ? ['items' => [], 'page' => 1, 'lastPage' => 1]
                : $this->creditosSinIdentificar($apertura, max(1, $request->integer('creditos', 1))),
            'canIdentify' => $identifica,
            'firmantes' => $identifica ? User::query()
                ->where('is_active', true)
                ->whereNotNull('position')
                ->orderBy('name')
                ->get(['id', 'name', 'position']) : [],
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

        return to_route('multas.caja.index');
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

    private function bankPanel(CashBox $caja, CashBookOpening $apertura): FineBankPanelData
    {
        $cuenta = BankAccount::query()->findOrFail($apertura->bank_account_id);
        $ultimo = $this->saldos->of($cuenta->id);

        $enElLibro = DB::table('journal_lines')
            ->join('financial_events', 'financial_events.id', '=', 'journal_lines.financial_event_id')
            ->where('journal_lines.cash_box_id', $caja->id)
            ->where('journal_lines.bank_account_id', $cuenta->id)
            ->where('journal_lines.account_code', LedgerAccount::BankAccount->value)
            ->whereIn('financial_events.status', ['posted', 'reversed'])
            ->selectRaw('COALESCE(SUM(journal_lines.debit - journal_lines.credit), 0) AS saldo')
            ->value('saldo');

        /*
         * Lo que entró desde la apertura de la caja y nadie identificó:
         * es la cola de «pendiente de clasificación de caja» (M08), sin tabla
         * propia. La misma consulta que la cola, así que descarta también lo
         * ya imputado —la acreditación de un depósito de la caja, un pago
         * verificado— y lo que respalda un pago anterior vigente.
         */
        $pendientes = $this->creditos->pending($apertura);

        return new FineBankPanelData(
            bankAccountId: $cuenta->id,
            bankAccountLabel: $cuenta->label,
            latestStatementBalance: $ultimo['balance'] ?? null,
            latestStatementDate: $ultimo['date'] ?? null,
            bookBalance: Decimal::scale((string) ($enElLibro ?? '0')),
            unclassifiedCount: (clone $pendientes)->count(),
            unclassifiedAmount: Decimal::scale((string) (clone $pendientes)->sum('amount')),
        );
    }

    /**
     * Los créditos sin identificar, con los pagos informados del mismo
     * importe que podrían ser el suyo. Del más viejo al más nuevo: es lo que
     * lleva más tiempo esperando.
     *
     * @return array{items: list<FineUnidentifiedCreditData>, page: int, lastPage: int}
     */
    private function creditosSinIdentificar(CashBookOpening $apertura, int $pagina): array
    {
        $consulta = fn (int $pagina) => $this->creditos->pending($apertura)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->paginate(perPage: self::COLA, pageName: 'creditos', page: $pagina);

        $paginados = $consulta($pagina);

        // Una página que ya no existe —se identificaron los de la última— cae en la última.
        if ($paginados->currentPage() > $paginados->lastPage()) {
            $paginados = $consulta($paginados->lastPage());
        }
        $creditos = collect($paginados->items());

        $informados = FinePayment::query()
            ->where('status', FinePaymentStatus::Reported)
            // Lo que llega por el banco: el cobro de mostrador no se informa.
            ->whereNotIn('medium', [FinePaymentMedium::Cash, FinePaymentMedium::Cheque])
            ->whereIn('amount', $creditos->map(fn (BankTransaction $c): string => Decimal::abs($c->amount))->unique()->values()->all())
            ->with(['fine.fineCase', 'creator:id,name'])
            ->orderBy('presented_at')
            ->orderBy('id')
            ->get();

        $filas = array_values($creditos->map(fn (BankTransaction $credito): FineUnidentifiedCreditData => new FineUnidentifiedCreditData(
            credit: FineBankCreditData::fromModel($credito),
            reported: array_values($informados
                ->filter(fn (FinePayment $pago): bool => Decimal::equals($pago->amount, Decimal::abs($credito->amount)))
                ->map(fn (FinePayment $pago): FinePendingPaymentData => FinePendingPaymentData::fromModel($pago))
                ->all()),
        ))->all());

        return ['items' => $filas, 'page' => $paginados->currentPage(), 'lastPage' => $paginados->lastPage()];
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
