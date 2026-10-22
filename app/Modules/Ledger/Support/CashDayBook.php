<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventStatus;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Support\Money\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * El detalle del día: una fila por comprobante.
 *
 * Es lo que la planilla muestra entre el saldo inicial y los totales, y lo
 * que la pantalla de caja muestra debajo de los saldos. **Las dos leen de
 * acá**: si cada una armara su consulta, el día que se decida que un recibo
 * anulado no aparece habría que acordarse de cambiarlo en dos lugares, y
 * la pantalla y el papel empezarían a discrepar sin que nadie lo note.
 *
 * Sale de `receipts` y no de `journal_lines` porque es lo que el papel
 * lista: números de comprobante, no asientos.
 *
 * Cada fila viaja con dos identificadores además de su importe. El del
 * recibo y el de la cuota que lo originó, que acá es **un número y nada
 * más**: este módulo es el motor contable y no puede saber que existen
 * cuotas ni haberes —es lo que permite que Aranceles y Multas lo
 * reutilicen—. Quien lo resuelve es Haberes, que sí puede. Van igual
 * porque sin ellos la pantalla muestra un número de comprobante y ninguna
 * forma de averiguar de quién es.
 *
 * ─── Los hechos sin recibo ───────────────────────────────────────────
 *
 * El rechazo de un cheque y su reemplazo mueven la caja sin emitir recibo,
 * y sin una fila los totales de la planilla no cuadrarían con su detalle.
 * Van con su propia identidad —el asiento, `eventId`, y ningún recibo— y
 * con el importe de **las líneas reales** de efectivo, cheques y banco: un
 * rechazo en tránsito o ya acreditado no mueve ninguna de las tres y no
 * tiene fila; uno en el cajón baja los cheques. El rótulo va en `number`,
 * que es la columna que el papel usa para identificar cada renglón.
 *
 * @phpstan-type Fila array{number: string, receiptId: int|null, eventId: int|null, installmentId: int|null, cash: numeric-string, cheques: numeric-string, bank: numeric-string}
 */
final class CashDayBook
{
    /**
     * @return array{income: list<Fila>, expense: list<Fila>}
     */
    public function entries(
        int $cashBoxId,
        CarbonInterface $from,
        CarbonInterface $to,
        Currency $currency = Currency::Ars,
    ): array {
        $filas = DB::table('receipts')
            ->join('receipt_financial_events AS pivote', 'pivote.receipt_id', '=', 'receipts.id')
            ->join('financial_events', 'financial_events.id', '=', 'pivote.financial_event_id')
            ->where('financial_events.cash_box_id', $cashBoxId)
            ->whereIn('financial_events.status', [
                FinancialEventStatus::Posted->value,
                FinancialEventStatus::Reversed->value,
            ])
            ->whereDate('financial_events.event_date', '>=', $from)
            ->whereDate('financial_events.event_date', '<=', $to)
            ->whereExists(function ($query) use ($currency): void {
                $query->selectRaw('1')
                    ->from('journal_lines')
                    ->whereColumn('journal_lines.financial_event_id', 'financial_events.id')
                    ->where('journal_lines.currency', $currency->value);
            })
            /*
             * Un recibo anulado no aparece: el talonario del área se rompe
             * y el papel tampoco lo tiene. Lo que sí queda es el asiento de
             * reversión, ya reflejado en los totales.
             */
            ->where('receipts.status', '!=', 'voided')
            ->select([
                'receipts.id',
                'receipts.receipt_type',
                'receipts.medium_snapshot',
                'receipts.amount',
                'receipts.talonario_number',
                'receipts.formatted_number',
                'receipts.beneficiary_installment_id',
            ])
            ->distinct()
            ->orderBy('receipts.id')
            ->get();

        $libro = ['income' => [], 'expense' => []];

        foreach ($filas as $fila) {
            $bloque = $fila->receipt_type === 'income' ? 'income' : 'expense';

            $libro[$bloque][] = [
                /*
                 * El número del talonario primero: es el que el área
                 * reconoce (72190, 76397). El de la serie del sistema queda
                 * de respaldo para los comprobantes emitidos en línea, que
                 * no llevan talonario.
                 */
                'number' => (string) ($fila->talonario_number ?? $fila->formatted_number),
                'receiptId' => (int) $fila->id,
                'eventId' => null,
                /*
                 * Nulo si el comprobante no tiene cuota. La pantalla lo usa
                 * para decidir qué fila abre panel y cuál no —ofrecer uno
                 * que no lleva a ningún lado es peor que no ofrecerlo—.
                 */
                'installmentId' => $fila->beneficiary_installment_id === null
                    ? null
                    : (int) $fila->beneficiary_installment_id,
                'cash' => $fila->medium_snapshot === 'cash' ? Decimal::scale((string) $fila->amount) : '0.00',
                'cheques' => $fila->medium_snapshot === 'cheque' ? Decimal::scale((string) $fila->amount) : '0.00',
                'bank' => $fila->medium_snapshot === 'bank' ? Decimal::scale((string) $fila->amount) : '0.00',
            ];
        }

        foreach ($this->chequeIncidents($cashBoxId, $from, $to, $currency) as $bloque => $filasDelHecho) {
            array_push($libro[$bloque], ...$filasDelHecho);
        }

        return $libro;
    }

    /**
     * Los rechazos y reemplazos de cheques que movieron efectivo, cheques o
     * banco en el período: los rechazos como egreso y los reemplazos como
     * ingreso, que es como los reparte el cierre.
     *
     * @return array{income: list<Fila>, expense: list<Fila>}
     */
    private function chequeIncidents(int $cashBoxId, CarbonInterface $from, CarbonInterface $to, Currency $currency): array
    {
        $filas = DB::table('financial_events')
            ->join('journal_lines', 'journal_lines.financial_event_id', '=', 'financial_events.id')
            // El cheque del que se trata: el rechazado, en los dos hechos.
            ->join('cheque_rejections AS rechazo', function ($join): void {
                $join->on('rechazo.rejection_event_id', '=', 'financial_events.id')
                    ->orOn('rechazo.replacement_event_id', '=', 'financial_events.id');
            })
            ->join('fund_receipts AS cheque', 'cheque.id', '=', 'rechazo.fund_receipt_id')
            ->where('journal_lines.cash_box_id', $cashBoxId)
            ->where('journal_lines.currency', $currency->value)
            ->whereIn('journal_lines.account_code', [
                LedgerAccount::CashOnHand->value,
                LedgerAccount::ChequesInCustody->value,
                LedgerAccount::BankAccount->value,
            ])
            ->whereIn('financial_events.event_type', [
                FinancialEventType::ChequeRejected->value,
                FinancialEventType::ChequeReplaced->value,
            ])
            ->whereIn('financial_events.status', [
                FinancialEventStatus::Posted->value,
                FinancialEventStatus::Reversed->value,
            ])
            ->whereDate('financial_events.event_date', '>=', $from)
            ->whereDate('financial_events.event_date', '<=', $to)
            ->groupBy('financial_events.id', 'financial_events.event_type', 'cheque.cheque_number')
            ->select(['financial_events.id', 'financial_events.event_type', 'cheque.cheque_number'])
            ->selectRaw('SUM(CASE WHEN journal_lines.account_code = ? THEN journal_lines.debit - journal_lines.credit ELSE 0 END) AS cash', [LedgerAccount::CashOnHand->value])
            ->selectRaw('SUM(CASE WHEN journal_lines.account_code = ? THEN journal_lines.debit - journal_lines.credit ELSE 0 END) AS cheques', [LedgerAccount::ChequesInCustody->value])
            ->selectRaw('SUM(CASE WHEN journal_lines.account_code = ? THEN journal_lines.debit - journal_lines.credit ELSE 0 END) AS bank', [LedgerAccount::BankAccount->value])
            ->orderBy('financial_events.id')
            ->get();

        $libro = ['income' => [], 'expense' => []];

        foreach ($filas as $fila) {
            $rechazo = $fila->event_type === FinancialEventType::ChequeRejected->value;
            // El egreso se muestra en positivo, como los recibos de egreso.
            $signo = fn (string $neto): string => Decimal::scale($rechazo ? Decimal::sub('0', $neto) : $neto);
            $importes = [
                'cash' => $signo((string) $fila->cash),
                'cheques' => $signo((string) $fila->cheques),
                'bank' => $signo((string) $fila->bank),
            ];

            if (Decimal::equals($importes['cash'], '0') && Decimal::equals($importes['cheques'], '0') && Decimal::equals($importes['bank'], '0')) {
                continue;
            }

            $libro[$rechazo ? 'expense' : 'income'][] = [
                'number' => $rechazo
                    ? sprintf('Cheque n.º %s rechazado', $fila->cheque_number)
                    : sprintf('Reemplazo del cheque n.º %s — no es recaudación', $fila->cheque_number),
                'receiptId' => null,
                'eventId' => (int) $fila->id,
                'installmentId' => null,
                ...$importes,
            ];
        }

        return $libro;
    }
}
