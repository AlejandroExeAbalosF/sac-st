<?php

declare(strict_types=1);

namespace App\Modules\Multas\Support;

use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Multas\Models\FineMonthlyReport;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Qué entra en la rendición mensual de Multas (P02) y en cuál de sus dos
 * informes.
 *
 * La rendición informa los ingresos **acreditados en la cuenta**: un pago
 * por banco, por la fecha de su movimiento; un cobro de la caja, por la
 * acreditación de su depósito —lo cobrado el 04/09 y acreditado el 11/09
 * figura el 11/09—. Un cobro que todavía no se acreditó no entra en ninguna.
 *
 * Cada pago va a **un solo informe**: el del mes de su **reconocimiento**,
 * la fecha del asiento que lo da por cobrado contra la cuenta —la
 * verificación del pago por banco, o el más tardío entre el cobro en caja y
 * la confirmación de la acreditación de su depósito—. Si se acreditó ese
 * mismo mes, va **en término**; si se acreditó antes, **fuera de término**.
 *
 * El corte es el **fin del mes calendario**, no su cierre manual (decisión
 * del usuario, 29/10/2026): un crédito del 29/10 reconocido el 29/10 va en
 * término en octubre; reconocido el 02/11, fuera de término en noviembre,
 * aunque octubre siguiera sin cerrar. Los asientos llevan la fecha del día
 * en que se cargan y un mes cerrado no admite asientos con su fecha, así que
 * lo emitido no cambia.
 *
 * @phpstan-type Fila array{date: string, origin: string, voucher: string, caption: string, caseLabel: string, amount: numeric-string, receipt: string, rejected: bool}
 */
final class FineRendition
{
    /**
     * Las filas de un informe del mes de ese cierre, por fecha de acreditación.
     *
     * @return list<Fila>
     */
    public function rows(PeriodClosing $closing, string $kind): array
    {
        $mes = $closing->period_from->startOfMonth();
        $filas = [];

        foreach ($this->recognizedIn($mes) as $pago) {
            $texto = fn (string $columna): string => is_scalar($pago[$columna] ?? null) ? (string) $pago[$columna] : '';
            $acreditado = CarbonImmutable::parse($texto('credited_on'));
            $enTermino = $acreditado->format('Y-m') === $mes->format('Y-m');

            if ($enTermino !== ($kind === FineMonthlyReport::ON_TIME)) {
                continue;
            }

            $filas[] = [
                'date' => $acreditado->toDateString(),
                'origin' => $this->origin($texto('medium')),
                'voucher' => $texto('voucher'),
                'caption' => $texto('caption'),
                'caseLabel' => $texto('case_label'),
                'amount' => Decimal::scale($texto('amount') ?: '0'),
                'receipt' => $texto('receipt'),
                'rejected' => (bool) ($pago['rejected'] ?? false),
            ];
        }

        usort($filas, fn (array $a, array $b): int => [$a['date'], $a['receipt']] <=> [$b['date'], $b['receipt']]);

        return $filas;
    }

    /**
     * Los pagos aplicados reconocidos dentro del mes, acreditados ese mes o
     * antes.
     *
     * @return list<array<string, mixed>>
     */
    private function recognizedIn(CarbonImmutable $mes): array
    {
        $desde = $mes->toDateString();
        $hasta = $mes->endOfMonth()->toDateString();

        // Pagos por banco: el movimiento del extracto que respalda la recepción.
        $banco = DB::table('fine_payment_applications AS aplicacion')
            ->join('fine_payments AS pago', 'pago.id', '=', 'aplicacion.fine_payment_id')
            ->join('financial_events AS aplicada', 'aplicada.id', '=', 'aplicacion.application_event_id')
            ->join('fund_receipts AS recepcion', 'recepcion.id', '=', 'aplicacion.fund_receipt_id')
            ->join('bank_transaction_allocations AS imputacion', function ($join): void {
                $join->on('imputacion.financial_event_id', '=', 'recepcion.financial_event_id')
                    ->whereNull('imputacion.reversal_of_id');
            })
            ->join('bank_transactions AS movimiento', 'movimiento.id', '=', 'imputacion.bank_transaction_id')
            ->leftJoin('fine_payment_receipts AS papel', 'papel.fine_payment_id', '=', 'pago.id')
            ->leftJoin('receipts AS recibo', 'recibo.id', '=', 'papel.receipt_id')
            ->whereNotIn('pago.medium', ['cash', 'cheque'])
            ->where('movimiento.transaction_date', '<=', $hasta)
            ->whereBetween('aplicada.event_date', [$desde, $hasta])
            ->select([
                'pago.medium', 'aplicacion.amount', 'movimiento.transaction_date AS credited_on',
                'movimiento.operation_id AS voucher',
            ])
            ->selectRaw('FALSE AS rejected');

        // Cobros de la caja: la acreditación del depósito de su instrumento vigente.
        $caja = DB::table('fine_payment_applications AS aplicacion')
            ->join('fine_payments AS pago', 'pago.id', '=', 'aplicacion.fine_payment_id')
            ->join('financial_events AS aplicada', 'aplicada.id', '=', 'aplicacion.application_event_id')
            ->join('cash_to_bank_transfer_items AS item', DB::raw('item.fund_receipt_id'), '=', DB::raw('fund_receipt_tip(aplicacion.fund_receipt_id)'))
            ->join('cash_to_bank_transfers AS traslado', 'traslado.id', '=', 'item.cash_to_bank_transfer_id')
            ->join('financial_events AS acreditada', 'acreditada.id', '=', 'traslado.credit_event_id')
            ->join('bank_transaction_allocations AS imputacion', function ($join): void {
                $join->on('imputacion.financial_event_id', '=', 'traslado.credit_event_id')
                    ->whereNull('imputacion.reversal_of_id');
            })
            ->join('bank_transactions AS movimiento', 'movimiento.id', '=', 'imputacion.bank_transaction_id')
            ->leftJoin('fine_payment_receipts AS papel', 'papel.fine_payment_id', '=', 'pago.id')
            ->leftJoin('receipts AS recibo', 'recibo.id', '=', 'papel.receipt_id')
            ->where('traslado.status', 'bank_confirmed')
            ->where('movimiento.transaction_date', '<=', $hasta)
            ->whereRaw('GREATEST(aplicada.event_date, acreditada.event_date) BETWEEN ? AND ?', [$desde, $hasta])
            ->select([
                'pago.medium', 'aplicacion.amount', 'movimiento.transaction_date AS credited_on',
            ])
            ->selectRaw('COALESCE(traslado.deposit_operation_number, movimiento.operation_id) AS voucher')
            // El cheque acreditado que el banco después rechazó: se acreditó, y se marca.
            ->selectRaw('EXISTS (SELECT 1 FROM cheque_rejections r WHERE r.fund_receipt_id = item.fund_receipt_id) AS rejected');

        foreach ([$banco, $caja] as $consulta) {
            $consulta->addSelect([
                DB::raw("papel.snapshot->>'caption' AS caption"),
                DB::raw("papel.snapshot->>'caseLabel' AS case_label"),
                DB::raw('COALESCE(recibo.talonario_number, recibo.formatted_number) AS receipt'),
            ]);
        }

        return array_values($banco->unionAll($caja)->get()->map(fn (object $fila): array => (array) $fila)->all());
    }

    /** Cómo entró, como lo escribe la planilla del área: CC, Trans., Cheque. */
    private function origin(string $medio): string
    {
        return match ($medio) {
            'cash', 'cheque' => 'CC',
            'bank_transfer' => 'Trans.',
            'direct_deposit' => 'Depósito',
            'echeq' => 'Cheque',
            default => $medio,
        };
    }
}
