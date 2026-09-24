<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Http\Controllers\Concerns\SelectsCurrency;
use App\Modules\Ledger\Http\Requests\RegisterOpeningBalanceRequest;
use App\Modules\Ledger\Models\CashCountLine;
use App\Modules\Ledger\Models\JournalLine;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La apertura de los libros.
 *
 * **Es el primer acto de la vida del sistema y ocurre una sola vez.** El día
 * que arranque hay plata física en el cajón que él no conoce; sin este
 * asiento el saldo teórico empieza en cero y el primer arqueo da una
 * diferencia igual a todo el saldo histórico.
 *
 * Hasta ahora solo se podía invocar por consola, y un sistema que maneja
 * dinero de terceros no puede pedir que alguien abra un `tinker` en
 * producción para declarar ocho millones de pesos.
 *
 * **Si la caja ya está abierta no hay formulario**: hay un asiento que
 * mostrar. Corregirlo no es volver a abrir —eso duplicaría el saldo— sino
 * revertir el asiento por el camino de siempre y abrir de nuevo.
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
            'bankAccounts' => $this->bankAccounts(),
            'bankAccountCode' => LedgerAccount::BankAccount->value,
            'existing' => $apertura === null ? null : [
                'date' => $apertura->event_date->toDateString(),
                'postedAt' => $apertura->posted_at?->toIso8601String(),
                'description' => $apertura->description,
                'lines' => $this->linesOf((int) $apertura->id),
            ],
        ]);
    }

    public function store(RegisterOpeningBalanceRequest $request): RedirectResponse
    {
        $this->abrir->handle(
            cashBoxId: (int) $this->cashBox()->id,
            balances: $request->balances(),
            date: CarbonImmutable::parse((string) $request->validated('date')),
            currency: Currency::from((string) $request->validated('currency')),
            bankAccountId: $request->validated('bankAccountId') === null
                ? null
                : (int) $request->validated('bankAccountId'),
            actorId: $request->user()?->id,
            cheques: $request->cheques(),
            denominations: $request->denominations(),
            notes: $request->validated('notes'),
        );

        return to_route('caja.dia')->with('status', 'Libros abiertos con el saldo declarado.');
    }

    /**
     * Las cuentas del organismo, para la pata bancaria.
     *
     * @return list<array{id: int, label: string}>
     */
    private function bankAccounts(): array
    {
        $cuentas = DB::table('bank_accounts')
            ->where('is_active', true)
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
     * Las patas del asiento, para poder mostrarlo tal como quedó.
     *
     * @return list<array{account: string, label: string, amount: numeric-string}>
     */
    private function linesOf(int $eventId): array
    {
        $lineas = [];

        $filas = JournalLine::query()
            ->where('financial_event_id', $eventId)
            ->where('debit', '>', 0)
            ->orderBy('id')
            ->get(['account_code', 'debit']);

        foreach ($filas as $linea) {
            // El modelo ya lo devuelve como enum: `account_code` está casteado.
            $cuenta = $linea->account_code;

            $lineas[] = [
                'account' => $cuenta->value,
                'label' => $cuenta->label(),
                'amount' => $linea->debit,
            ];
        }

        return $lineas;
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
