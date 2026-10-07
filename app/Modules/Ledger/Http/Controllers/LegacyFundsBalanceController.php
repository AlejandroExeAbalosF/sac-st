<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Http\Controllers\Concerns\SelectsCurrency;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Ledger\Support\LegacyFundsByPlace;
use App\Modules\Shared\Models\CashBox;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El saldo del sistema anterior: cuánto queda, dónde está y qué se reservó.
 *
 * Es una pantalla de consulta. Responde la pregunta que dice cuándo se
 * apaga la operación en paralelo —**cuánto queda del sistema anterior sin
 * reservar**—, y el día que llega a cero no queda ningún caso viejo sin
 * resolver.
 *
 * Nada se paga desde acá. Un caso viejo se paga cargando su expediente
 * histórico, reservando la plata para la cuota y pagándola por el circuito
 * normal: así cada peso queda atado a su cuota. El pago suelto, sin cuota,
 * se retiró.
 */
final class LegacyFundsBalanceController extends Controller
{
    use SelectsCurrency;

    public function __construct(
        private readonly CashBalance $saldos,
        private readonly LegacyFundsByPlace $porLugar,
    ) {}

    public function index(Request $request): Response
    {
        $caja = $this->cashBox();
        $moneda = $this->selectedCurrency($request);
        $id = (int) $caja->id;

        return Inertia::render('caja/saldo-anterior', [
            'selected' => [
                'cashBoxId' => $id,
                'currency' => $moneda->value,
                'cashBoxName' => $caja->name,
            ],
            'balances' => [
                /*
                 * Lo del sistema anterior que todavía no tiene dueño. Baja
                 * con cada reserva para una cuota histórica, y el día que
                 * llega a cero se apaga la operación en paralelo.
                 */
                'pending' => $this->saldos->of(LedgerAccount::LegacyFunds, $id, $moneda),
                'cash' => $this->saldos->of(LedgerAccount::CashOnHand, $id, $moneda),
                'cheques' => $this->saldos->of(LedgerAccount::ChequesInCustody, $id, $moneda),
                // «DEPOSITOS DIRECTOS»: lo que las empresas depositaron derecho en la cuenta.
                'bank' => $this->saldos->of(LedgerAccount::BankAccount, $id, $moneda),
            ],
            'legacyByPlace' => $this->legacyByPlace($id, $moneda),
            'setAside' => $this->setAside($id, $moneda),
        ]);
    }

    /**
     * Lo reservado para cuotas de expedientes históricos.
     *
     * No se le pagó a nadie todavía: se dijo de quién es. Se lee del libro
     * y no de las cuotas —esta pantalla es de Ledger, que no sabe de
     * expedientes—; el detalle es la descripción que dejó quien reservó. Lo
     * liberado después vuelve al saldo, y se muestra al lado para que la
     * suma cierre.
     *
     * @return list<array{id: int, date: string, description: string|null, amount: numeric-string, released: numeric-string}>
     */
    private function setAside(int $cashBoxId, Currency $currency): array
    {
        $eventos = DB::table('financial_events')
            ->join('journal_lines', 'journal_lines.financial_event_id', '=', 'financial_events.id')
            ->where('financial_events.cash_box_id', $cashBoxId)
            ->where('financial_events.event_type', FinancialEventType::LegacyFundsAllocated->value)
            ->where('financial_events.status', '<>', 'draft')
            ->where('journal_lines.account_code', LedgerAccount::LegacyFunds->value)
            ->where('journal_lines.currency', $currency->value)
            ->groupBy('financial_events.id', 'financial_events.event_date', 'financial_events.description')
            ->orderByDesc('financial_events.event_date')
            ->orderByDesc('financial_events.id')
            ->limit(100)
            ->get([
                'financial_events.id',
                'financial_events.event_date',
                'financial_events.description',
                DB::raw('SUM(journal_lines.debit) AS importe'),
            ]);

        $liberado = DB::table('financial_events')
            ->join('journal_lines', 'journal_lines.financial_event_id', '=', 'financial_events.id')
            ->whereIn('financial_events.reversal_of_id', $eventos->pluck('id'))
            ->where('journal_lines.account_code', LedgerAccount::LegacyFunds->value)
            ->groupBy('financial_events.reversal_of_id')
            ->selectRaw('financial_events.reversal_of_id AS apartado, SUM(journal_lines.credit) AS liberado')
            ->pluck('liberado', 'apartado');

        $lista = [];

        foreach ($eventos as $evento) {
            $lista[] = [
                'id' => (int) $evento->id,
                'date' => CarbonImmutable::parse((string) $evento->event_date)->toDateString(),
                'description' => is_string($evento->description) ? $evento->description : null,
                'amount' => Decimal::scale((string) $evento->importe),
                'released' => Decimal::scale((string) ($liberado[$evento->id] ?? '0')),
            ];
        }

        return $lista;
    }

    /**
     * Dónde está lo que queda del sistema anterior.
     *
     * Cada lugar guarda también plata que entró después, así que el saldo
     * de la tarjeta no dice cuánto de eso es viejo. Esto sí: es el tope de
     * lo que se reserva de cada lugar.
     *
     * @return array{cash: numeric-string, cheques: numeric-string, bank: numeric-string}
     */
    private function legacyByPlace(int $cashBoxId, Currency $currency): array
    {
        $lugares = $this->porLugar->breakdown($cashBoxId, $currency);
        $banco = '0.00';

        foreach ($lugares['banks'] as $queda) {
            $banco = Decimal::add($banco, $queda);
        }

        return ['cash' => $lugares['cash'], 'cheques' => $lugares['cheques'], 'bank' => $banco];
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
