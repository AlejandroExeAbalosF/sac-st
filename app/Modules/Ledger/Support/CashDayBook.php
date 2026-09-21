<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventStatus;
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
 * @phpstan-type Fila array{number: string, receiptId: int, installmentId: int|null, cash: numeric-string, cheques: numeric-string, bank: numeric-string}
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
                /*
                 * Nulo en los pagos de haberes anteriores: no cuelgan de
                 * ninguna cuota del sistema y su `expediente_number_snapshot`
                 * es una referencia al registro manual. La pantalla lo usa
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

        return $libro;
    }
}
