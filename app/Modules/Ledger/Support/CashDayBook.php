<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventStatus;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Support\Money\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
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
 * Lo mismo la reversión de un recibo anulado otro día: entre lo que dice el
 * saldo inicial y lo que dice el total del día tiene que haber una fila que lo
 * explique. Cómo lo asienta el área en papel **no está relevado**.
 *
 * @phpstan-type Fila array{number: string, receiptId: int|null, eventId: int|null, installmentId: int|null, cash: numeric-string, cheques: numeric-string, bank: numeric-string}
 */
final class CashDayBook
{
    public function __construct(private readonly CashBoxModules $modules) {}

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
             *
             * Salvo el cheque rechazado que se anuló: su recepción no se revierte,
             * porque el cheque sí entró y salió con el rechazo. Sin este renglón,
             * el egreso del rechazo quedaría sin su ingreso.
             *
             * Y salvo el que se anuló otro día: ese día el dinero figuró, y el
             * total del día lo cuenta. Sigue en su fecha y la reversión aparece
             * en la suya (`laterVoids`).
             */
            ->where(function ($query): void {
                // Uno reemplazado tampoco: su lugar lo ocupa el reemplazante.
                $query->whereNotIn('receipts.status', ['voided', 'replaced'])
                    ->orWhereExists(function ($rechazado): void {
                        $rechazado->selectRaw('1')
                            ->from('fund_receipts AS cheque')
                            ->join('cheque_rejections AS rechazo', 'rechazo.fund_receipt_id', '=', 'cheque.id')
                            ->whereColumn('cheque.financial_event_id', 'pivote.financial_event_id');
                    })
                    ->orWhere(function ($anulado): void {
                        $anulado->where('receipts.status', 'voided')
                            ->whereExists(function ($reversion): void {
                                $reversion->selectRaw('1')
                                    ->from('financial_events AS reversion')
                                    ->join('journal_lines AS linea', 'linea.financial_event_id', '=', 'reversion.id')
                                    ->where(function ($vinculo): void {
                                        $this->documentsTheMoneyOf($vinculo, 'reversion.reversal_of_id');
                                    })
                                    ->whereColumn('reversion.event_date', '>', 'financial_events.event_date')
                                    ->whereIn('reversion.status', [
                                        FinancialEventStatus::Posted->value,
                                        FinancialEventStatus::Reversed->value,
                                    ])
                                    ->whereIn('linea.account_code', $this->locationAccounts());
                            })
                            /*
                             * Si después de anularlo se emitió otro sobre el mismo
                             * dinero, el renglón de ese día es el del otro.
                             */
                            ->whereNotExists(function ($posterior): void {
                                $posterior->selectRaw('1')
                                    ->from('receipt_financial_events AS otro_pivote')
                                    ->join('receipts AS otro', 'otro.id', '=', 'otro_pivote.receipt_id')
                                    ->whereColumn('otro_pivote.financial_event_id', 'pivote.financial_event_id')
                                    ->whereColumn('otro.id', '>', 'receipts.id')
                                    ->whereColumn('otro.created_at', '>=', 'receipts.voided_at');
                            });
                    });
            })
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
        $ingresoPrevio = $this->modules->forCashBox($cashBoxId)->bankIncomePrecedesReceipt();
        $delPeriodo = $ingresoPrevio
            ? $this->bankIncomeOfReceipts(array_values($filas->map(fn (object $fila): int => (int) $fila->id)->all()), $cashBoxId, $from, $to, $currency)
            : [];

        /*
         * Lo que salió del banco por cada recibo de egreso bancario. Suele ser
         * lo mismo que dice el papel; la excepción es la comisión que el banco
         * cobró por la transferencia y absorbió el beneficiario: el recibo
         * dice lo que él recibió, y la cuenta perdió además la comisión.
         */
        $salidasBancarias = $this->bankIncomeOfReceipts(
            array_values($filas
                ->filter(fn (object $fila): bool => $fila->receipt_type === 'expense' && $fila->medium_snapshot === 'bank')
                ->map(fn (object $fila): int => (int) $fila->id)
                ->all()),
            $cashBoxId, $from, $to, $currency,
        );

        foreach ($filas as $fila) {
            $bloque = $fila->receipt_type === 'income' ? 'income' : 'expense';
            $banco = $fila->medium_snapshot === 'bank' ? Decimal::scale((string) $fila->amount) : '0.00';

            if ($ingresoPrevio && $fila->medium_snapshot === 'bank') {
                $banco = $delPeriodo[(int) $fila->id] ?? '0.00';

                // Emitido hoy sobre dinero que entró antes: su renglón está en esos días.
                if (Decimal::equals($banco, '0')) {
                    continue;
                }
            }

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
                'bank' => $banco,
            ];

            /*
             * La comisión va en su propio renglón y no sumada al del recibo:
             * el renglón del recibo tiene que decir lo mismo que el papel, y
             * el total del día, lo mismo que el cierre.
             */
            if (! $ingresoPrevio && isset($salidasBancarias[(int) $fila->id])) {
                $comision = Decimal::sub(Decimal::sub('0', $salidasBancarias[(int) $fila->id]), $banco);

                if (Decimal::isNegative(Decimal::sub('0', $comision))) {
                    $libro['expense'][] = [
                        'number' => sprintf('Comisión bancaria del recibo %s', $fila->talonario_number ?? $fila->formatted_number),
                        'receiptId' => null,
                        'eventId' => null,
                        'installmentId' => null,
                        'cash' => '0.00',
                        'cheques' => '0.00',
                        'bank' => Decimal::scale($comision),
                    ];
                }
            }
        }

        foreach ($this->chequeIncidents($cashBoxId, $from, $to, $currency) as $bloque => $filasDelHecho) {
            array_push($libro[$bloque], ...$filasDelHecho);
        }

        if ($ingresoPrevio) {
            array_push($libro['income'], ...$this->bankIncomeWithoutReceipt($cashBoxId, $from, $to, $currency));
        }

        foreach ($this->laterVoids($cashBoxId, $from, $to, $currency) as $bloque => $filasDelHecho) {
            array_push($libro[$bloque], ...$filasDelHecho);
        }

        return $libro;
    }

    /**
     * Lo que entró al banco en el período por cada recibo, según los hechos que
     * documenta.
     *
     * @param  list<int>  $recibos
     * @return array<int, numeric-string>
     */
    private function bankIncomeOfReceipts(array $recibos, int $cashBoxId, CarbonInterface $from, CarbonInterface $to, Currency $currency): array
    {
        $filas = $this->bankLines($cashBoxId, $from, $to, $currency)
            ->join('receipt_financial_events AS pivote', 'pivote.financial_event_id', '=', 'financial_events.id')
            ->whereIn('pivote.receipt_id', $recibos)
            // Revertido el mismo día, ese dinero no figuró: un rango de días no lo suma.
            ->whereNotExists(function ($revertido): void {
                $revertido->selectRaw('1')
                    ->from('financial_events AS reversion')
                    ->whereColumn('reversion.reversal_of_id', 'financial_events.id')
                    ->whereColumn('reversion.event_date', 'financial_events.event_date')
                    ->whereIn('reversion.status', [
                        FinancialEventStatus::Posted->value,
                        FinancialEventStatus::Reversed->value,
                    ]);
            })
            ->groupBy('pivote.receipt_id')
            ->select('pivote.receipt_id')
            ->selectRaw('SUM(journal_lines.debit - journal_lines.credit) AS bank')
            ->get();

        $porRecibo = [];

        foreach ($filas as $fila) {
            $porRecibo[(int) $fila->receipt_id] = Decimal::scale((string) $fila->bank);
        }

        return $porRecibo;
    }

    /**
     * Lo que entró al banco sin recibo todavía —un ingreso parcial— y su reversión
     * si el cobro se anuló. Sin estas filas, los totales del día no cuadran con su
     * detalle. Al emitir el recibo, el mismo importe pasa a figurar con su número.
     *
     * @return list<Fila>
     */
    private function bankIncomeWithoutReceipt(int $cashBoxId, CarbonInterface $from, CarbonInterface $to, Currency $currency): array
    {
        $filas = $this->bankLines($cashBoxId, $from, $to, $currency)
            ->leftJoin('financial_events AS revertido', 'revertido.id', '=', 'financial_events.reversal_of_id')
            ->where(function ($hecho): void {
                $hecho->where('financial_events.event_type', FinancialEventType::FundsReceived->value)
                    ->orWhere('revertido.event_type', FinancialEventType::FundsReceived->value);
            })
            ->whereNotExists(function ($documentado): void {
                $documentado->selectRaw('1')
                    ->from('receipt_financial_events AS pivote')
                    ->whereRaw('pivote.financial_event_id = COALESCE(financial_events.reversal_of_id, financial_events.id)');
            })
            ->groupBy('financial_events.id', 'financial_events.reversal_of_id')
            ->select('financial_events.id', 'financial_events.reversal_of_id')
            ->selectRaw('SUM(journal_lines.debit - journal_lines.credit) AS bank')
            ->orderBy('financial_events.id')
            ->get();

        $libro = [];

        foreach ($filas as $fila) {
            $libro[] = [
                'number' => $fila->reversal_of_id === null ? 'Ingreso por banco, sin recibo todavía' : 'Reversión de un ingreso por banco sin recibo',
                'receiptId' => null,
                'eventId' => (int) $fila->id,
                'installmentId' => null,
                'cash' => '0.00',
                'cheques' => '0.00',
                'bank' => Decimal::scale((string) $fila->bank),
            ];
        }

        return $libro;
    }

    /** Las líneas de la cuenta bancaria de la caja en el período, asentadas. */
    private function bankLines(int $cashBoxId, CarbonInterface $from, CarbonInterface $to, Currency $currency): Builder
    {
        return DB::table('financial_events')
            ->join('journal_lines', 'journal_lines.financial_event_id', '=', 'financial_events.id')
            ->where('journal_lines.cash_box_id', $cashBoxId)
            ->where('journal_lines.currency', $currency->value)
            ->where('journal_lines.account_code', LedgerAccount::BankAccount->value)
            ->whereIn('financial_events.status', [
                FinancialEventStatus::Posted->value,
                FinancialEventStatus::Reversed->value,
            ])
            ->whereDate('financial_events.event_date', '>=', $from)
            ->whereDate('financial_events.event_date', '<=', $to);
    }

    /** @return list<string> Las cuentas de las tres columnas de la planilla. */
    private function locationAccounts(): array
    {
        return [
            LedgerAccount::CashOnHand->value,
            LedgerAccount::ChequesInCustody->value,
            LedgerAccount::BankAccount->value,
        ];
    }

    /**
     * Si `pivote` documenta el dinero de ese asiento: lo cita directamente, como
     * en Multas y Aranceles, o cita la asignación financiada con esa recepción.
     */
    private function documentsTheMoneyOf(Builder $query, string $evento): void
    {
        $query->whereColumn('pivote.financial_event_id', $evento)
            ->orWhereExists(function ($asignada) use ($evento): void {
                $asignada->selectRaw('1')
                    ->from('funding_allocations AS asignacion')
                    ->join('fund_receipts AS recepcion', 'recepcion.id', '=', 'asignacion.fund_receipt_id')
                    ->whereColumn('asignacion.allocation_event_id', 'pivote.financial_event_id')
                    ->whereColumn('recepcion.financial_event_id', $evento);
            });
    }

    /**
     * Las reversiones de recibos anulados después del día en que entró el dinero.
     *
     * Van en el bloque del recibo y restando, porque es como las suma el cierre:
     * una reversión pertenece al renglón del hecho que revierte. Si se anula el
     * mismo día no hay fila: el recibo tampoco aparece y el día neto da cero.
     *
     * @return array{income: list<Fila>, expense: list<Fila>}
     */
    private function laterVoids(int $cashBoxId, CarbonInterface $from, CarbonInterface $to, Currency $currency): array
    {
        // El recibo anulado que documentaba ese dinero, y el último si hubo varios.
        $recibo = fn () => DB::table('receipts AS recibo')
            ->join('receipt_financial_events AS pivote', 'pivote.receipt_id', '=', 'recibo.id')
            ->where(function ($vinculo): void {
                $this->documentsTheMoneyOf($vinculo, 'original.id');
            })
            ->where('recibo.status', 'voided')
            ->orderByDesc('recibo.id')
            ->limit(1);

        $filas = DB::table('financial_events AS reversion')
            ->join('financial_events AS original', 'original.id', '=', 'reversion.reversal_of_id')
            ->join('journal_lines', 'journal_lines.financial_event_id', '=', 'reversion.id')
            ->where('journal_lines.cash_box_id', $cashBoxId)
            ->where('journal_lines.currency', $currency->value)
            ->whereIn('journal_lines.account_code', $this->locationAccounts())
            ->whereIn('reversion.status', [
                FinancialEventStatus::Posted->value,
                FinancialEventStatus::Reversed->value,
            ])
            ->whereDate('reversion.event_date', '>=', $from)
            ->whereDate('reversion.event_date', '<=', $to)
            ->whereColumn('reversion.event_date', '>', 'original.event_date')
            ->groupBy('reversion.id', 'original.id')
            ->select('reversion.id')
            ->selectSub($recibo()->selectRaw('COALESCE(recibo.talonario_number, recibo.formatted_number)'), 'number')
            ->selectSub($recibo()->select('recibo.receipt_type'), 'receipt_type')
            ->selectRaw('SUM(CASE WHEN journal_lines.account_code = ? THEN journal_lines.debit - journal_lines.credit ELSE 0 END) AS cash', [LedgerAccount::CashOnHand->value])
            ->selectRaw('SUM(CASE WHEN journal_lines.account_code = ? THEN journal_lines.debit - journal_lines.credit ELSE 0 END) AS cheques', [LedgerAccount::ChequesInCustody->value])
            ->selectRaw('SUM(CASE WHEN journal_lines.account_code = ? THEN journal_lines.debit - journal_lines.credit ELSE 0 END) AS bank', [LedgerAccount::BankAccount->value])
            ->orderBy('reversion.id')
            ->get();

        $libro = ['income' => [], 'expense' => []];

        foreach ($filas as $fila) {
            // La reversión de algo que no tuvo recibo no tiene fila en este libro.
            if ($fila->number === null) {
                continue;
            }

            $ingreso = $fila->receipt_type === 'income';
            // Un ingreso revertido acredita la cuenta; un egreso revertido, la debita.
            $importe = fn (string $neto): string => Decimal::scale($ingreso ? $neto : Decimal::sub('0', $neto));

            $libro[$ingreso ? 'income' : 'expense'][] = [
                'number' => sprintf('Anulación del recibo %s', $fila->number),
                'receiptId' => null,
                'eventId' => (int) $fila->id,
                'installmentId' => null,
                'cash' => $importe((string) $fila->cash),
                'cheques' => $importe((string) $fila->cheques),
                'bank' => $importe((string) $fila->bank),
            ];
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
