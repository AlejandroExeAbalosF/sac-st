<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Excel;

use App\Modules\Ledger\Enums\CashCountStatus;
use App\Modules\Ledger\Enums\PeriodType;
use App\Modules\Ledger\Models\CashCount;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Ledger\Support\CashBoxModules;
use App\Modules\Ledger\Support\CashDayBook;
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
        private readonly CashBoxModules $modules,
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
        $modulo = $this->modules->for($closing->cashBox->code);

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
            /*
             * El inventario es una posición de cierre, a esa fecha, y quién
             * es cada cheque lo sabe el módulo de la caja. Sus columnas y lo
             * que significa la de banco se congelan con los datos.
             */
            cheques: $modulo->chequesInCustody($closing),
            bankDepositsLabel: $this->bankDepositsLabel($closing),
            chequeColumns: $modulo->chequeColumns(),
            bankColumnNote: $modulo->bankColumnNote(),
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
