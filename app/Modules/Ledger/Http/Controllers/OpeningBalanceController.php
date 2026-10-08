<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Http\Controllers\Concerns\SelectsCurrency;
use App\Modules\Ledger\Http\Requests\RegisterOpeningBalanceRequest;
use App\Modules\Ledger\Models\CashCountLine;
use App\Modules\Ledger\Models\JournalLine;
use App\Modules\Shared\Models\CashBox;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La apertura de los libros.
 *
 * Ocurre una sola vez; el porqué, en la página `caja/apertura`.
 *
 * **Si el libro ya está abierto no hay formulario**: hay una apertura que
 * mostrar. No se rehace —duplicaría el saldo histórico—, y
 * `cash_book_openings` es append-only.
 */
final class OpeningBalanceController extends Controller
{
    use SelectsCurrency;

    public function __construct(
        private readonly RegisterOpeningBalance $abrir,
    ) {}

    public function index(Request $request): Response
    {
        $caja = $this->cashBox();
        $moneda = $this->selectedCurrency($request);

        $apertura = $this->abrir->existingFor((int) $caja->id, $moneda);

        return Inertia::render('caja/apertura', [
            'selected' => [
                'cashBoxId' => (int) $caja->id,
                'currency' => $moneda->value,
                'cashBoxName' => $caja->name,
            ],
            /*
             * Las cuentas de ubicación, con su etiqueta. La apertura declara
             * **dónde está** el dinero, nunca de quién es: eso exige el
             * expediente y la cuota que el sistema todavía no tiene, y es
             * justamente el trabajo que `LEGACY_FUNDS` posterga.
             */
            /*
             * Los billetes que la pantalla ofrece para contar el cajón. El
             * efectivo de la apertura se cuenta como cualquier arqueo.
             */
            'suggestedDenominations' => CashCountLine::suggestedDenominations($moneda),
            'accounts' => array_map(
                fn (LedgerAccount $cuenta): array => [
                    'code' => $cuenta->value,
                    'label' => $cuenta->label(),
                ],
                RegisterOpeningBalanceRequest::openableAccounts(),
            ),
            /*
             * Para la pata de «DEPOSITOS DIRECTOS». Se leen con `DB::table`
             * y no con el modelo de Banking: `bank_accounts` es el maestro
             * institucional de qué cuentas tiene el organismo, no dominio
             * de Banking, y es el mismo criterio con el que `journal_lines`
             * le pone una FK sin conocer su modelo.
             */
            'bankAccounts' => $this->bankAccounts($moneda),
            'bankAccountCode' => LedgerAccount::BankAccount->value,
            'existing' => $apertura === null ? null : [
                'date' => $apertura->opened_on->toDateString(),
                'postedAt' => $apertura->created_at->toIso8601String(),
                'description' => $apertura->notes,
                'isEmpty' => $apertura->isEmpty(),
                'lines' => $this->linesOf((int) $caja->id, $moneda),
            ],
        ]);
    }

    public function store(RegisterOpeningBalanceRequest $request): RedirectResponse
    {
        $moneda = Currency::from((string) $request->validated('currency'));

        $this->abrir->handle(
            cashBoxId: (int) $this->cashBox()->id,
            balances: $request->balances(),
            date: CarbonImmutable::parse((string) $request->validated('date')),
            currency: $moneda,
            bankAccountId: $request->validated('bankAccountId') === null
                ? null
                : (int) $request->validated('bankAccountId'),
            actorId: (int) $request->user()?->id,
            cheques: $request->cheques(),
            denominations: $request->denominations(),
            notes: $request->validated('notes'),
            declaredEmpty: $request->boolean('declaredEmpty'),
        );

        /*
         * De vuelta al libro que se acaba de abrir: quien abrió los dólares
         * viene a operar en dólares. Los pesos no se anotan en la dirección.
         */
        return to_route('caja.dia', $moneda === Currency::Ars ? [] : ['moneda' => mb_strtolower($moneda->value)])
            ->with('status', 'Libros abiertos con el saldo declarado.');
    }

    /**
     * Las cuentas del organismo en la moneda del libro, para la pata bancaria.
     *
     * Dólares en una cuenta en pesos no existen, y la base rechazaría la
     * línea. Si no hay ninguna en esa moneda la lista llega vacía y la
     * pantalla apaga el renglón.
     *
     * @return list<array{id: int, label: string}>
     */
    private function bankAccounts(Currency $moneda): array
    {
        $cuentas = DB::table('bank_accounts')
            ->where('is_active', true)
            ->where('currency', $moneda->value)
            ->orderBy('label')
            ->get(['id', 'bank_name', 'account_number']);

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
     * Lo que la apertura declaró, por cuenta.
     *
     * Suma todos los asientos de apertura del libro: el principal y el de
     * cada cheque detallado. Mirar solo el principal dejaba afuera la
     * cartera cuando se detallaba, y no mostraba nada cuando solo había
     * cheques.
     *
     * @return list<array{account: string, label: string, amount: numeric-string}>
     */
    private function linesOf(int $cashBoxId, Currency $moneda): array
    {
        $porCuenta = [];

        $filas = JournalLine::query()
            ->join('financial_events', 'financial_events.id', '=', 'journal_lines.financial_event_id')
            ->where('financial_events.event_type', FinancialEventType::OpeningBalance->value)
            ->where('financial_events.cash_box_id', $cashBoxId)
            ->where('journal_lines.currency', $moneda->value)
            ->where('journal_lines.debit', '>', 0)
            ->orderBy('journal_lines.id')
            ->get(['journal_lines.account_code', 'journal_lines.debit']);

        foreach ($filas as $linea) {
            // El modelo ya lo devuelve como enum: `account_code` está casteado.
            $cuenta = $linea->account_code;

            $porCuenta[$cuenta->value] = [
                'account' => $cuenta->value,
                'label' => $cuenta->label(),
                'amount' => Decimal::add($porCuenta[$cuenta->value]['amount'] ?? '0.00', $linea->debit),
            ];
        }

        return array_values($porCuenta);
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
