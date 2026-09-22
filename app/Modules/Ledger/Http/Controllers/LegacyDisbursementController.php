<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ledger\Actions\PayLegacyBeneficiary;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Http\Controllers\Concerns\SelectsCurrency;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\Person;
use App\Modules\Shared\Models\Receipt;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Los pagos de haberes anteriores al sistema.
 *
 * La pantalla existe para responder una sola pregunta —**cuánto queda del
 * sistema anterior**— y ofrecer el acto que la baja. Ese número es el que
 * dice cuándo se puede apagar la planilla en paralelo: el día que llegue a
 * cero, no queda ningún caso viejo por pagar.
 */
final class LegacyDisbursementController extends Controller
{
    use SelectsCurrency;

    public function __construct(
        private readonly CashBalance $saldos,
        private readonly PayLegacyBeneficiary $pagar,
    ) {}

    public function index(Request $request): Response
    {
        $caja = $this->cashBox();
        $moneda = $this->selectedCurrency($request);
        $id = (int) $caja->id;

        return Inertia::render('caja/pagos-anteriores', [
            'selected' => [
                'cashBoxId' => $id,
                'currency' => $moneda->value,
                'cashBoxName' => $caja->name,
            ],
            'balances' => [
                /*
                 * Lo que falta pagar del sistema anterior. Es el número que
                 * ordena toda la pantalla: baja con cada pago y el día que
                 * llegue a cero se apaga la operación en paralelo.
                 */
                'pending' => $this->saldos->of(LedgerAccount::LegacyFunds, $id, $moneda),
                'cash' => $this->saldos->of(LedgerAccount::CashOnHand, $id, $moneda),
                'cheques' => $this->saldos->of(LedgerAccount::ChequesInCustody, $id, $moneda),
                /*
                 * «DEPOSITOS DIRECTOS»: lo que las empresas depositaron
                 * derecho en la cuenta de la Secretaría. Un caso viejo que
                 * llegó por ahí se paga por transferencia, no por el cajón.
                 */
                'bank' => $this->saldos->of(LedgerAccount::BankAccount, $id, $moneda),
            ],
            'bankAccounts' => $this->bankAccounts(),
            'payments' => $this->history($id, $moneda),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'cashBoxId' => ['required', 'integer', Rule::exists('cash_boxes', 'id')->where('is_active', true)],
            'personId' => ['required', 'integer', Rule::exists('people', 'id')->where('is_active', true)],
            'amount' => ['required', 'numeric', 'gt:0'],
            'legacyReference' => ['required', 'string', 'max:60'],
            'paymentDate' => ['required', 'date', 'before_or_equal:'.BusinessDate::today()->toDateString()],
            'medium' => ['required', Rule::enum(PaymentMedium::class)],
            /*
             * Obligatoria solo cuando el dinero sale del banco. El Action
             * lo vuelve a exigir: acá se gana el mensaje al lado del campo.
             */
            'bankAccountId' => [
                'required_if:medium,'.PaymentMedium::Bank->value,
                'nullable',
                'integer',
                Rule::exists('bank_accounts', 'id')->where('is_active', true),
            ],
            'currency' => ['required', Rule::enum(Currency::class)],
            'talonarioNumber' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:300'],
        ], [
            'legacyReference.required' => 'Sin la referencia al registro manual el pago sale sin respaldo.',
            'paymentDate.before_or_equal' => 'Un pago no puede tener fecha futura.',
            'bankAccountId.required_if' => 'Un pago por transferencia tiene que decir de qué cuenta bancaria sale.',
        ]);

        /** @var Person $beneficiario */
        $beneficiario = Person::query()->findOrFail($datos['personId']);

        $recibo = $this->pagar->handle(
            cashBoxId: (int) $this->cashBox()->id,
            beneficiary: $beneficiario,
            amount: Decimal::parse((string) $datos['amount']) ?? '0.00',
            legacyReference: (string) $datos['legacyReference'],
            paymentDate: CarbonImmutable::parse((string) $datos['paymentDate']),
            medium: PaymentMedium::from((string) $datos['medium']),
            currency: Currency::from((string) $datos['currency']),
            bankAccountId: isset($datos['bankAccountId']) ? (int) $datos['bankAccountId'] : null,
            actorId: $request->user()?->id,
            talonarioNumber: $datos['talonarioNumber'] ?? null,
            printsTalonarioNumber: ($datos['talonarioNumber'] ?? null) !== null,
            notes: $datos['notes'] ?? null,
        );

        return back()->with('status', "Pago registrado con el recibo {$recibo->formatted_number}.");
    }

    /**
     * Los pagos ya hechos, con su referencia al registro manual.
     *
     * Se leen de `receipts` y no del libro: el comprobante es lo que el
     * beneficiario se llevó, y su número es por lo que alguien va a
     * preguntar. El asiento está detrás, ligado por el pivote.
     *
     * @return list<array<string, mixed>>
     */
    private function history(int $cashBoxId, Currency $currency): array
    {
        $recibos = Receipt::query()
            ->join('receipt_financial_events AS pivote', 'pivote.receipt_id', '=', 'receipts.id')
            ->join('financial_events', 'financial_events.id', '=', 'pivote.financial_event_id')
            ->where('financial_events.cash_box_id', $cashBoxId)
            ->where('financial_events.event_type', FinancialEventType::LegacyDisbursement)
            ->where('receipts.receipt_type', ReceiptType::Expense)
            ->whereExists(function ($query) use ($currency): void {
                $query->selectRaw('1')
                    ->from('journal_lines')
                    ->whereColumn('journal_lines.financial_event_id', 'financial_events.id')
                    ->where('journal_lines.currency', $currency->value);
            })
            ->orderByDesc('receipts.issue_date')
            ->orderByDesc('receipts.id')
            ->limit(100)
            ->get([
                'receipts.id',
                'receipts.formatted_number',
                'receipts.talonario_number',
                'receipts.beneficiary_name_snapshot',
                'receipts.expediente_number_snapshot',
                'receipts.medium_snapshot',
                'receipts.amount',
                'receipts.issue_date',
                'receipts.status',
            ]);

        $historial = [];

        foreach ($recibos as $recibo) {
            $historial[] = [
                'id' => (int) $recibo->id,
                'number' => $recibo->talonario_number ?? $recibo->formatted_number,
                'formattedNumber' => $recibo->formatted_number,
                'beneficiary' => $recibo->beneficiary_name_snapshot,
                'reference' => $recibo->expediente_number_snapshot,
                'medium' => $recibo->medium_snapshot,
                'amount' => $recibo->amount,
                'date' => $recibo->issue_date->toDateString(),
                'voided' => $recibo->status->isActive() === false,
            ];
        }

        return $historial;
    }

    /**
     * Las cuentas del organismo, para el pago por transferencia.
     *
     * Se leen con `DB::table` y no con el modelo de Banking: `bank_accounts`
     * es el maestro institucional de qué cuentas tiene el organismo, no
     * dominio de Banking, y es el mismo criterio con el que `journal_lines`
     * le pone una FK sin conocer su modelo.
     *
     * @return list<array{id: int, label: string}>
     */
    private function bankAccounts(): array
    {
        $cuentas = DB::table('bank_accounts')
            ->where('is_active', true)
            ->orderBy('label')
            ->get(['id', 'label', 'bank_name', 'account_number']);

        $lista = [];

        foreach ($cuentas as $cuenta) {
            $lista[] = [
                'id' => (int) $cuenta->id,
                'label' => sprintf('%s · CTA. %s', $cuenta->bank_name, $cuenta->account_number),
            ];
        }

        return $lista;
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
}
