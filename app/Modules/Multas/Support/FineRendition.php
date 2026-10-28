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
 * Cada pago va a **un solo informe**: el del primer mes, desde el de su
 * acreditación, cuyo cierre mensual es posterior a su reconocimiento —el
 * más tardío entre la emisión del recibo y la confirmación del crédito—.
 * «Posterior» no se mide con el reloj, que se guarda al segundo: el
 * reconocimiento es un asiento de la caja, y la evidencia del cierre anota
 * el último asiento de la caja que vio (`covers_through_event_id`).
 * Si es el mismo mes de la acreditación, va **en término**; si es uno
 * posterior, **fuera de término** en ese mes (decisión del usuario,
 * 28/10/2026). Un crédito del 30/09 reconocido el 02/10 con septiembre
 * cerrado el 05/10 va en término en septiembre; reconocido el 06/10, fuera
 * de término en octubre. Lo que llega tarde nunca altera un informe ya
 * emitido: cae en el siguiente.
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
        $cierres = $this->monthlyClosings((int) $closing->cash_box_id);
        $hasta = $cierres[$mes->format('Y-m')] ?? null;

        if ($hasta === null) {
            return [];
        }

        $filas = [];

        foreach ($this->candidates($mes, $hasta) as $pago) {
            $texto = fn (string $columna): string => is_scalar($pago[$columna] ?? null) ? (string) $pago[$columna] : '';
            $acreditado = CarbonImmutable::parse($texto('credited_on'));
            $reconocido = (int) $texto('recognized_by');
            $destino = $this->reportMonth($acreditado, $reconocido, $cierres);

            if ($destino !== $mes->format('Y-m')) {
                continue;
            }

            $enTermino = $acreditado->format('Y-m') === $destino;

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
     * El primer mes, desde el de la acreditación, cuyo cierre vio el asiento
     * del reconocimiento. Un mes sin cierre —anterior a la apertura, o
     * reabierto— no recibe a nadie: el pago pasa al siguiente cerrado.
     *
     * @param  array<string, int>  $cierres
     */
    private function reportMonth(CarbonImmutable $acreditado, int $reconocido, array $cierres): ?string
    {
        foreach ($cierres as $mes => $vioHasta) {
            if ($mes >= $acreditado->format('Y-m') && $reconocido <= $vioHasta) {
                return $mes;
            }
        }

        return null;
    }

    /**
     * Los cierres mensuales cerrados de la caja, por mes `AAAA-MM`, en orden,
     * con el último asiento que vio la evidencia de su versión vigente.
     *
     * @return array<string, int>
     */
    private function monthlyClosings(int $cashBoxId): array
    {
        return DB::table('period_closings AS cierre')
            ->join('period_closing_evidence AS evidencia', function ($join): void {
                $join->on('evidencia.period_closing_id', '=', 'cierre.id')
                    ->on('evidencia.version', '=', 'cierre.evidence_version');
            })
            ->where('cierre.cash_box_id', $cashBoxId)
            ->where('cierre.period_type', 'monthly')
            ->where('cierre.status', 'closed')
            ->orderBy('cierre.period_from')
            ->get(['cierre.period_from', 'evidencia.covers_through_event_id'])
            ->mapWithKeys(fn (object $c): array => [
                CarbonImmutable::parse(is_string($c->period_from) ? $c->period_from : '')->format('Y-m') => is_numeric($c->covers_through_event_id) ? (int) $c->covers_through_event_id : 0,
            ])
            ->all();
    }

    /**
     * Los pagos aplicados que pueden caer en el informe de ese mes: los
     * acreditados hasta fin de mes y reconocidos hasta su cierre —el último
     * asiento que vio—. Cuál de ellos cae acá lo decide `reportMonth`.
     *
     * @return list<array<string, mixed>>
     */
    private function candidates(CarbonImmutable $mes, int $hasta): array
    {
        $fin = $mes->endOfMonth()->toDateString();

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
            ->where('movimiento.transaction_date', '<=', $fin)
            ->where('aplicada.id', '<=', $hasta)
            ->select([
                'pago.medium', 'aplicacion.amount', 'movimiento.transaction_date AS credited_on',
                'aplicada.id AS recognized_by', 'movimiento.operation_id AS voucher',
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
            ->where('movimiento.transaction_date', '<=', $fin)
            ->whereRaw('GREATEST(aplicada.id, acreditada.id) <= ?', [$hasta])
            ->select([
                'pago.medium', 'aplicacion.amount', 'movimiento.transaction_date AS credited_on',
            ])
            ->selectRaw('GREATEST(aplicada.id, acreditada.id) AS recognized_by')
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
