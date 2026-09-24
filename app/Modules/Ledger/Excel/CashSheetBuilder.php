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
 * **Todo sale del snapshot y de los comprobantes del período**, nunca de un
 * recálculo: reexportar el 02/06 dentro de un año tiene que devolver los
 * mismos números que el área firmó, aunque el sublibro haya cambiado de ahí
 * en adelante.
 *
 * El detalle —una fila por recibo— sale de `receipts` y no de
 * `journal_lines`, porque es lo que la planilla muestra: el papel lista
 * números de recibo, no asientos. Y muestra el **número del talonario**
 * cuando lo hay, que es el que el área reconoce (72190, 76397), con el
 * número de serie del sistema como respaldo.
 */
final class CashSheetBuilder
{
    public function __construct(
        private readonly CashDayBook $dayBook,
    ) {}

    public function build(PeriodClosing $closing): CashSheet
    {
        $desde = $closing->period_from;
        $hasta = $closing->period_to;

        $recibos = $this->dayBook->entries($closing->cash_box_id, $desde, $hasta);

        return new CashSheet(
            closing: $closing,
            title: sprintf(
                'PLANILLA DE %s %s',
                mb_strtoupper($closing->cashBox->name),
                $desde->equalTo($hasta)
                    ? $desde->format('d/m/Y')
                    : $desde->format('d/m/Y').' AL '.$hasta->format('d/m/Y'),
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
            ->with('allLines')
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
     * Sale de `fund_receipts` con `cheque_status = 'in_custody'` — sin tabla
     * de arqueo propia, tal como el §9.4 anticipó. El expediente y el
     * beneficiario vienen de los **snapshots del recibo**, que están en
     * `receipts`: son texto congelado al emitir, no una relación hacia
     * Haberes, y por eso Ledger puede leerlos sin romper la frontera.
     *
     * ─── Por qué el camino pasa por `funding_allocations` ────────────────
     *
     * Un cobro produce **dos** eventos: la recepción del dinero y su
     * asignación a la cuota. El cheque cuelga del primero y el recibo del
     * segundo —`receipt_financial_events` vincula asignaciones, y su ficha
     * lo dice: «un recibo de ingreso puede respaldar varias asignaciones»—.
     * Unirlos por el evento de recepción devuelve el importe correcto y
     * **todas las columnas que identifican el cheque en blanco**, que es
     * exactamente lo que el reverso necesita.
     *
     * `funding_allocations` es la única tabla que registra qué recepción
     * pagó qué cuota, así que es el único puente posible. Se lee con
     * `DB::table` y sin importar el modelo, que vive en Haberes: la
     * frontera prohíbe que Ledger **conozca el modelo** de otro módulo, y
     * el arch test lo verifica sobre las clases. Aun así es el punto más
     * incómodo de este archivo: si el reverso crece, conviene que el
     * inventario de cheques lo provea Haberes y Ledger solo lo dibuje.
     *
     * @return list<array{receipt: string, expediente: string, company: string, beneficiary: string, number: string, bank: string, date: string, amount: numeric-string}>
     */
    private function chequesInCustody(PeriodClosing $closing): array
    {
        $filas = DB::table('fund_receipts')
            ->leftJoin('funding_allocations AS imputacion', 'imputacion.fund_receipt_id', '=', 'fund_receipts.id')
            ->leftJoin('receipt_financial_events AS pivote', 'pivote.financial_event_id', '=', 'imputacion.allocation_event_id')
            ->leftJoin('receipts', 'receipts.id', '=', 'pivote.receipt_id')
            ->where('fund_receipts.cash_box_id', $closing->cash_box_id)
            ->where('fund_receipts.currency', $closing->currency->value)
            ->where('fund_receipts.cheque_status', 'in_custody')
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
            $inventario[] = [
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
        }

        return $inventario;
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
