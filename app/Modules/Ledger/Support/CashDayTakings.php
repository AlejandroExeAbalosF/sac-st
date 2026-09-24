<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventStatus;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Support\Money\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Cuánto de lo que entró hoy sigue en el cajón al terminar el día.
 *
 * Es el número que la planilla del área llama `RECAUDACION DEL DIA`, y no es
 * lo que entró: es **lo que entró y no volvió a salir**. En la planilla de
 * junio de 2026 la distinción es enorme —el 02/06 entraron 24.985.600 y la
 * recaudación del día fue 1.118.200— porque mucha plata se cobra el mismo día
 * que se recibe: el empleador deposita y el beneficiario viene a buscarla.
 *
 * El resto del cajón es el «saldo del día anterior». El sistema lo calcula:
 * mientras ese número fuera libre, **el arqueo siempre podía cuadrarse** con
 * solo declarar la diferencia que faltaba. Calcularlo permite comparar el
 * conteo contra algo que el operador no eligió.
 *
 * ─── Por qué se puede calcular sin salir de Ledger ────────────────────────
 *
 * Haría falta saber qué pago consumió qué recepción, y el egreso al
 * beneficiario vive en `disbursements`, que es de Haberes. Pero no hace falta
 * llegar hasta ahí: la línea del asiento que acredita `CASH_ON_HAND` ya lleva
 * `beneficiary_installment_id` —lo escribe `EntryLine::forInstallment()`— y
 * `funding_allocations` une esa cuota con la recepción que la financió. Las
 * dos tablas son de Ledger.
 *
 * Este reparto identifica el origen contable, no los billetes físicos. Solo
 * coincide con el conteo separado de «lo de hoy» si el área conserva esa
 * separación en el cajón o paga cada obligación con los fondos que la
 * financiaron. Si mezcla todo el efectivo, el libro permite conocer el total
 * del cajón pero no reconstruir de qué montón salió cada billete.
 *
 * Es un `id` de Haberes usado como identificador opaco, nunca como relación:
 * acá nadie pregunta qué es una cuota, solo si dos líneas hablan de la misma.
 */
final class CashDayTakings
{
    /**
     * @return numeric-string
     */
    public function of(int $cashBoxId, CarbonInterface $date, Currency $currency): string
    {
        $entro = $this->cashReceived($cashBoxId, $date, $currency);
        $salio = $this->paidBackSameDay($cashBoxId, $date, $currency);
        $seDeposito = $this->depositedSameDay($cashBoxId, $date, $currency);

        $quedo = Decimal::sub(Decimal::sub($entro, $salio), $seDeposito);

        /*
         * Nunca negativo. Pagar más de lo que entró hoy significa que el
         * resto sali del fondo anterior, no que la recaudación del día sea
         * un faltante.
         */
        return Decimal::isNegative($quedo) ? '0.00' : $quedo;
    }

    /** @return numeric-string */
    private function cashReceived(int $cashBoxId, CarbonInterface $date, Currency $currency): string
    {
        $total = DB::table('fund_receipts')
            ->join('financial_events', 'financial_events.id', '=', 'fund_receipts.financial_event_id')
            ->where('fund_receipts.cash_box_id', $cashBoxId)
            ->where('fund_receipts.currency', $currency->value)
            ->where('fund_receipts.medium', 'cash')
            ->whereDate('fund_receipts.received_date', $date)
            ->where('financial_events.status', FinancialEventStatus::Posted->value)
            ->sum('fund_receipts.amount');

        return Decimal::scale((string) $total);
    }

    /**
     * Lo que entró hoy y se pagó hoy.
     *
     * Se suman las asignaciones —no el importe del pago— porque son las que
     * dicen de qué recepción salió cada peso. Una cuota financiada por dos
     * recepciones de días distintos aporta solo la parte de hoy.
     *
     * @return numeric-string
     */
    private function paidBackSameDay(int $cashBoxId, CarbonInterface $date, Currency $currency): string
    {
        /*
         * La cuota no viaja en la línea del efectivo sino en la de
         * `BENEFICIARY_FUNDS`, que es su contrapartida: el egreso debita al
         * beneficiario y acredita el cajón. Por eso se busca el asiento que
         * sacó efectivo y se lee la cuota de sus otras líneas.
         */
        $salioEfectivoHoy = DB::table('journal_lines')
            ->select('journal_lines.financial_event_id')
            ->join('financial_events', 'financial_events.id', '=', 'journal_lines.financial_event_id')
            ->where('journal_lines.cash_box_id', $cashBoxId)
            ->where('journal_lines.currency', $currency->value)
            ->where('journal_lines.account_code', LedgerAccount::CashOnHand->value)
            ->where('journal_lines.credit', '>', 0)
            ->where('financial_events.status', FinancialEventStatus::Posted->value)
            ->whereDate('financial_events.event_date', $date);

        $pagadasHoy = DB::table('journal_lines')
            ->distinct()
            ->select('journal_lines.beneficiary_installment_id')
            ->whereNotNull('journal_lines.beneficiary_installment_id')
            ->whereIn('journal_lines.financial_event_id', $salioEfectivoHoy);

        $total = DB::table('funding_allocations')
            ->join('fund_receipts', 'fund_receipts.id', '=', 'funding_allocations.fund_receipt_id')
            ->whereIn('funding_allocations.beneficiary_installment_id', $pagadasHoy)
            ->where('fund_receipts.cash_box_id', $cashBoxId)
            ->where('fund_receipts.currency', $currency->value)
            ->where('fund_receipts.medium', 'cash')
            ->whereDate('fund_receipts.received_date', $date)
            ->whereNull('funding_allocations.reversal_of_id')
            ->sum('funding_allocations.amount');

        return Decimal::scale((string) $total);
    }

    /**
     * Lo recibido hoy que salió hoy del cajón rumbo al banco.
     *
     * Un traslado de plata vieja achica el fajo, no la recaudación del día;
     * por eso se siguen los ítems hasta la recepción y se toman solo los que
     * entraron en esta misma jornada. Una cancelación posterior no reescribe
     * el pasado: solo se excluye si el asiento inverso ya existía en el día
     * que se está calculando.
     *
     * @return numeric-string
     */
    private function depositedSameDay(int $cashBoxId, CarbonInterface $date, Currency $currency): string
    {
        $total = DB::table('cash_to_bank_transfer_items AS item')
            ->join('cash_to_bank_transfers AS traslado', 'traslado.id', '=', 'item.cash_to_bank_transfer_id')
            ->join('financial_events AS deposito', 'deposito.id', '=', 'traslado.deposit_event_id')
            ->join('fund_receipts AS recepcion', 'recepcion.id', '=', 'item.fund_receipt_id')
            ->where('traslado.cash_box_id', $cashBoxId)
            ->where('recepcion.currency', $currency->value)
            ->where('recepcion.medium', 'cash')
            ->whereDate('recepcion.received_date', $date)
            ->whereDate('deposito.event_date', $date)
            ->where('deposito.status', FinancialEventStatus::Posted->value)
            ->whereNotExists(function ($query) use ($date): void {
                $query->selectRaw('1')
                    ->from('financial_events AS reversion')
                    ->whereColumn('reversion.reversal_of_id', 'deposito.id')
                    ->whereDate('reversion.event_date', '<=', $date)
                    ->where('reversion.status', FinancialEventStatus::Posted->value);
            })
            ->sum('item.amount');

        return Decimal::scale((string) $total);
    }
}
