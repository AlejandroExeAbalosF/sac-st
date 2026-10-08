<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Excel;

use App\Modules\Ledger\Enums\CashCountStatus;
use App\Modules\Ledger\Enums\PeriodType;
use App\Modules\Ledger\Models\CashCount;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Ledger\Support\CashDayBook;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Arma los datos de la planilla a partir de un cierre.
 *
 * **Se arma una sola vez, al cerrar, y queda congelado** en
 * `period_closing_evidence` junto con los totales. Reexportar el 02/06
 * dentro de un año tiene que devolver lo que el área firmó, aunque el
 * sublibro haya cambiado de ahí en adelante; volver a consultar el estado
 * vivo —un cheque que después se depositó, un recibo anulado— reescribiría
 * en silencio la evidencia de un día ya cerrado. `build()` lee lo
 * congelado; `captureCurrent()` es lo único que consulta el libro, y solo
 * lo llama `ClosePeriod` con la caja bloqueada.
 *
 * El detalle —una fila por recibo— sale de `receipts` y no de
 * `journal_lines`, porque es lo que la planilla muestra: el papel lista
 * números de recibo, no asientos. Y muestra el **número del talonario**
 * cuando lo hay, que es el que el área reconoce (72190, 76397), con el
 * número de serie del sistema como respaldo. Todo en la moneda del cierre:
 * pesos y dólares son dos planillas.
 */
final class CashSheetBuilder
{
    public function __construct(
        private readonly CashDayBook $dayBook,
        private readonly CashSheetEvidence $evidence,
    ) {}

    public function build(PeriodClosing $closing): CashSheet
    {
        return $this->evidence->read($closing);
    }

    /** Se usa únicamente al cerrar, con la caja bloqueada. */
    public function captureCurrent(PeriodClosing $closing): CashSheet
    {
        $desde = $closing->period_from;
        $hasta = $closing->period_to;

        $recibos = $this->dayBook->entries($closing->cash_box_id, $desde, $hasta, $closing->currency);

        return new CashSheet(
            closing: $closing,
            title: sprintf(
                'PLANILLA DE %s %s · %s',
                mb_strtoupper($closing->cashBox->name),
                $desde->equalTo($hasta)
                    ? $desde->format('d/m/Y')
                    : $desde->format('d/m/Y').' AL '.$hasta->format('d/m/Y'),
                $closing->currency->value,
            ),
            /*
             * Los nombres de hoja son los que el área ya usa: `CAJA 020626`
             * y `REVERSO 020626`. Cambiarlos por algo más prolijo obligaría
             * a quien busca una hoja a aprender un esquema nuevo.
             *
             * El mensual lleva `MES` en el nombre, y no es cosmético: su
             * período arranca el día 1, así que sin distintivo la hoja del
             * mes y la del primer día se llamarían igual — y Excel no
             * admite dos hojas con el mismo título, de modo que el libro
             * entero fallaría al guardarse.
             */
            sheetName: $this->sheetName($closing, 'CAJA'),
            reverseSheetName: $this->sheetName($closing, 'REVERSO'),
            income: $recibos['income'],
            expense: $recibos['expense'],
            /*
             * El arqueo es un acto del día. Repetirlo en la hoja del mes
             * sugeriría un conteo mensual que nunca ocurrió; el inventario
             * de cheques sí es una posición de cierre y se conserva.
             */
            count: $closing->period_type === PeriodType::Monthly
                ? null
                : $this->cashCount($closing),
            cheques: $this->chequesInCustody($closing),
            bankDepositsLabel: $this->bankDepositsLabel($closing),
        );
    }

    private function sheetName(PeriodClosing $closing, string $prefix): string
    {
        return $closing->period_type === PeriodType::Monthly
            ? sprintf('%s MES %s', $prefix, $closing->period_from->format('my'))
            : sprintf('%s %s', $prefix, $closing->period_from->format('dmy'));
    }

    /**
     * El arqueo que respalda el cierre.
     *
     * Se toma el de mayor `sequence` del último día del período: si hubo
     * dos turnos, el que cierra la caja es el segundo. Y solo si quedó
     * firme — un borrador no respalda nada, aunque `ClosePeriod` ya impide
     * llegar hasta acá con uno abierto.
     */
    private function cashCount(PeriodClosing $closing): ?CashCount
    {
        return CashCount::query()
            ->with('lines')
            ->where('cash_box_id', $closing->cash_box_id)
            ->where('currency', $closing->currency)
            ->whereDate('counted_on', $closing->period_to)
            ->where('status', '!=', CashCountStatus::Draft)
            ->orderByDesc('sequence')
            ->first();
    }

    /**
     * El inventario de cheques, como el reverso lo lista.
     *
     * **Es la custodia a la fecha del cierre, no la de hoy**: los cheques
     * recibidos hasta ese día, menos los depositados, entregados o
     * revertidos hasta ese día. Filtrar por `cheque_status` respondía qué
     * sigue en custodia ahora, y un cierre hecho tarde —o uno reabierto y
     * vuelto a cerrar— perdía cheques que ese día estaban en el cajón
     * mientras su saldo los seguía contando. Se calcula al cerrar y queda
     * congelado.
     *
     * El expediente y el beneficiario vienen de los **snapshots del
     * recibo**, que están en `receipts`: son texto congelado al emitir, no
     * una relación hacia Haberes, y por eso Ledger puede leerlos sin romper
     * la frontera.
     *
     * ─── Por qué el camino pasa por `funding_allocations` ────────────────
     *
     * Un cobro produce **dos** eventos: la recepción del dinero y su
     * asignación a la cuota. El cheque cuelga del primero y el recibo del
     * segundo —`receipt_financial_events` vincula asignaciones—. Unirlos por
     * el evento de recepción devuelve el importe correcto y **todas las
     * columnas que identifican el cheque en blanco**, que es lo que el
     * reverso necesita. `funding_allocations` es la única tabla que registra
     * qué recepción pagó qué cuota, así que es el único puente posible. Se
     * lee con `DB::table` y sin importar el modelo, que vive en Haberes: la
     * frontera prohíbe que Ledger **conozca el modelo** de otro módulo. Un
     * cheque que financia varias cuotas aparece una vez, con todos sus
     * recibos.
     *
     * @return list<array{receipt: string, expediente: string, company: string, beneficiary: string, number: string, bank: string, date: string, amount: numeric-string}>
     */
    private function chequesInCustody(PeriodClosing $closing): array
    {
        $filas = DB::table('fund_receipts')
            ->join('financial_events AS reception', 'reception.id', '=', 'fund_receipts.financial_event_id')
            ->whereDate('reception.event_date', '<=', $closing->period_to)
            ->leftJoin('funding_allocations AS imputacion', function ($join) use ($closing): void {
                $join->on('imputacion.fund_receipt_id', '=', 'fund_receipts.id')
                    ->whereNull('imputacion.reversal_of_id')
                    ->whereRaw('(SELECT event_date FROM financial_events WHERE id = imputacion.allocation_event_id) <= ?', [$closing->period_to->toDateString()])
                    ->whereRaw('imputacion.amount > (SELECT COALESCE(SUM(r.amount), 0)
                        FROM funding_allocations r JOIN financial_events re ON re.id = r.allocation_event_id
                        WHERE r.reversal_of_id = imputacion.id AND re.event_date <= ?)', [$closing->period_to->toDateString()]);
            })
            ->leftJoin('receipt_financial_events AS pivote', 'pivote.financial_event_id', '=', 'imputacion.allocation_event_id')
            ->leftJoin('receipts', 'receipts.id', '=', 'pivote.receipt_id')
            ->where('fund_receipts.cash_box_id', $closing->cash_box_id)
            ->where('fund_receipts.currency', $closing->currency->value)
            ->where('fund_receipts.medium', 'cheque')
            // El estado actual no representa la custodia en un cierre tardío.
            ->whereNotExists(function ($query) use ($closing): void {
                $query->selectRaw('1')->from('financial_events AS reversal')
                    ->whereColumn('reversal.id', 'fund_receipts.reversal_event_id')
                    ->whereDate('reversal.event_date', '<=', $closing->period_to);
            })
            ->whereNotExists(function ($query) use ($closing): void {
                $query->selectRaw('1')->from('cash_to_bank_transfer_items AS item')
                    ->join('cash_to_bank_transfers AS transfer', 'transfer.id', '=', 'item.cash_to_bank_transfer_id')
                    ->join('financial_events AS deposit', 'deposit.id', '=', 'transfer.deposit_event_id')
                    ->whereColumn('item.fund_receipt_id', 'fund_receipts.id')
                    ->whereDate('deposit.event_date', '<=', $closing->period_to)
                    ->whereNotExists(function ($reversal) use ($closing): void {
                        $reversal->selectRaw('1')->from('financial_events AS undone')
                            ->whereColumn('undone.reversal_of_id', 'deposit.id')
                            ->whereDate('undone.event_date', '<=', $closing->period_to);
                    });
            })
            ->whereNotExists(function ($query) use ($closing): void {
                $query->selectRaw('1')->from('disbursements AS payment')
                    ->join('financial_events AS paid', 'paid.id', '=', 'payment.financial_event_id')
                    ->where('payment.method', 'cheque')
                    ->whereDate('paid.event_date', '<=', $closing->period_to)
                    ->whereRaw('(SELECT COALESCE(SUM(CASE WHEN a.reversal_of_id IS NULL THEN a.amount ELSE -a.amount END), 0)
                        FROM funding_allocations a JOIN financial_events ae ON ae.id = a.allocation_event_id
                        WHERE a.fund_receipt_id = fund_receipts.id
                          AND a.beneficiary_installment_id = payment.beneficiary_installment_id
                          AND ae.id <= paid.id) > 0')
                    ->whereNotExists(function ($reversal) use ($closing): void {
                        $reversal->selectRaw('1')->from('financial_events AS undone')
                            ->whereColumn('undone.reversal_of_id', 'paid.id')
                            ->whereDate('undone.event_date', '<=', $closing->period_to);
                    });
            })
            /*
             * El inventario es a la fecha del cierre: un cheque recibido
             * después no estaba en el cajón ese día.
             */
            ->whereDate('fund_receipts.received_date', '<=', $closing->period_to)
            ->select([
                // `id` va en el SELECT porque el DISTINCT lo exige para
                // poder ordenar por él.
                'fund_receipts.id',
                'fund_receipts.amount',
                'fund_receipts.cheque_number',
                'fund_receipts.cheque_bank',
                'fund_receipts.cheque_issue_date',
                'receipts.talonario_number',
                'receipts.formatted_number',
                /*
                 * El snapshot del recibo manda; el de la recepción es el
                 * respaldo. Un cheque cargado en la apertura no tiene
                 * recibo emitido ni cuota asignada, pero sí lo que decía
                 * el papel cuando se abrieron los libros, y el reverso lo
                 * lista igual. El día que ese cheque se impute a una cuota
                 * real, el recibo pasa a tener la última palabra.
                 */
                'receipts.expediente_number_snapshot',
                'receipts.counterparty_name_snapshot',
                'receipts.beneficiary_name_snapshot',
                'fund_receipts.expediente_number_snapshot AS expediente_declarado',
                'fund_receipts.counterparty_name_snapshot AS empresa_declarada',
                'fund_receipts.beneficiary_name_snapshot AS beneficiario_declarado',
            ])
            ->distinct()
            ->orderBy('fund_receipts.id')
            ->get();

        $inventario = [];

        foreach ($filas as $fila) {
            $detalle = [
                'receipt' => (string) ($fila->talonario_number ?? $fila->formatted_number ?? ''),
                'expediente' => (string) ($fila->expediente_number_snapshot ?? $fila->expediente_declarado ?? ''),
                'company' => (string) ($fila->counterparty_name_snapshot ?? $fila->empresa_declarada ?? ''),
                'beneficiary' => (string) ($fila->beneficiary_name_snapshot ?? $fila->beneficiario_declarado ?? ''),
                'number' => (string) ($fila->cheque_number ?? ''),
                'bank' => (string) ($fila->cheque_bank ?? ''),
                'date' => $fila->cheque_issue_date === null
                    ? ''
                    : date('d/m/Y', (int) strtotime((string) $fila->cheque_issue_date)),
                'amount' => Decimal::scale((string) $fila->amount),
            ];

            // Un cheque puede financiar varias cuotas: se identifican todas,
            // pero su importe físico se suma una sola vez en el inventario.
            if (isset($inventario[$fila->id])) {
                foreach (['receipt', 'expediente', 'company', 'beneficiary'] as $field) {
                    $detalle[$field] = implode(' / ', array_unique(array_filter([
                        ...explode(' / ', $inventario[$fila->id][$field]), $detalle[$field],
                    ])));
                }
            }
            $inventario[$fila->id] = $detalle;
        }

        return array_values($inventario);
    }

    /**
     * El rótulo de la fila de depósitos.
     *
     * La planilla escribe la cuenta a mano —«DEPOSITOS BANCO MACRO CTA.
     * 310000123456789»— y acá sale del maestro de cuentas: el día que se
     * cambie de cuenta, el papel del sistema cambia solo.
     *
     * Se consulta con `DB::table` y no con el modelo de Banking a
     * propósito: `bank_accounts` es el maestro institucional de qué cuentas
     * tiene el organismo, no dominio de Banking, y es el mismo criterio con
     * el que `journal_lines` le pone una FK sin conocer su modelo.
     */
    private function bankDepositsLabel(PeriodClosing $closing): string
    {
        $cuenta = DB::table('journal_lines')
            ->join('financial_events', 'financial_events.id', '=', 'journal_lines.financial_event_id')
            ->join('bank_accounts', 'bank_accounts.id', '=', 'journal_lines.bank_account_id')
            ->where('journal_lines.cash_box_id', $closing->cash_box_id)
            ->where('journal_lines.currency', $closing->currency->value)
            ->whereDate('financial_events.event_date', '>=', $closing->period_from)
            ->whereDate('financial_events.event_date', '<=', $closing->period_to)
            ->whereNotNull('journal_lines.bank_account_id')
            ->selectRaw("bank_accounts.bank_name || ' CTA. ' || bank_accounts.account_number AS rotulo")
            ->value('rotulo');

        if ($cuenta === null) {
            // Sin depósitos en el período no hay cuenta que nombrar, y la
            // fila va igual con su cero: la planilla la arrastra siempre.
            return 'DEPOSITOS BANCO';
        }

        return 'DEPOSITOS '.mb_strtoupper((string) $cuenta);
    }
}
