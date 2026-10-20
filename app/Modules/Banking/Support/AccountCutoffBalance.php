<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Enums\ImportStatus;
use App\Modules\Banking\Enums\ParseStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccountOpening;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankStatementRow;
use App\Modules\Banking\Models\BankTransaction;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lo que el extracto importado demuestra del saldo de una cuenta.
 *
 * Sirve para dos cosas: comprobar que el saldo que se declara al corte es
 * el del banco, y mostrar si lo importado después del corte cierra contra
 * el último saldo informado.
 *
 * **Solo afirma lo que un archivo demuestra.** Los extractos se importan
 * por partes, y entre dos puede faltar un período: el saldo anterior al
 * primer movimiento de julio no es el saldo de mayo si junio no se importó.
 * Por eso cada saldo sale de las filas de un mismo archivo, en su orden
 * cronológico —el de `ParsedStatement::chronologicalRows`: fecha, y dentro
 * del día de abajo hacia arriba, porque MacroOnline exporta al revés—, y
 * no del orden en que los movimientos entraron a la base.
 */
final class AccountCutoffBalance
{
    public function __construct(private readonly BankDayOrder $orden) {}

    /**
     * El saldo al cierre de un día, si un archivo lo demuestra.
     *
     * Hace falta un extracto con filas a los dos lados del día: uno que
     * arranca después no dice nada de lo que pasó antes. Dentro de ese
     * archivo, el saldo sale de la primera fila posterior (su saldo menos lo
     * que ella movió) o, si no trae saldo, de la última del día.
     *
     * @return numeric-string|null
     */
    public function atEndOf(int $bankAccountId, CarbonInterface $day): ?string
    {
        $dia = $day->toDateString();

        /*
         * Por las filas y no por `period_from`/`period_to`: son las filas las
         * que muestran que el archivo atraviesa el día.
         */
        $filaDelLado = fn (string $operador) => fn ($q) => $q->selectRaw('1')
            ->from('bank_statement_rows')
            ->whereColumn('bank_statement_rows.bank_statement_import_id', 'bank_statement_imports.id')
            ->where('bank_statement_rows.parse_status', '<>', ParseStatus::Rejected->value)
            ->where('bank_statement_rows.parsed_transaction_date', $operador, $dia);

        $archivo = BankStatementImport::query()
            ->where('bank_account_id', $bankAccountId)
            ->where('status', ImportStatus::Completed->value)
            ->whereExists($filaDelLado('<='))
            ->whereExists($filaDelLado('>'))
            ->orderByDesc('id')
            ->first();

        if ($archivo === null) {
            return null;
        }

        /** @var BankStatementRow|null $siguiente */
        $siguiente = $this->chronological($this->rowsOf($archivo->id)->where('parsed_transaction_date', '>', $dia))->first();

        $movido = $siguiente === null ? null : $this->signed($siguiente);

        if ($siguiente?->parsed_balance_after !== null && $movido !== null) {
            return Decimal::sub($siguiente->parsed_balance_after, $movido);
        }

        /** @var BankStatementRow|null $anterior */
        $anterior = $this->chronological($this->rowsOf($archivo->id)->where('parsed_transaction_date', '<=', $dia), reverse: true)->first();

        return $anterior?->parsed_balance_after;
    }

    /**
     * El último saldo que informó un extracto, con su fecha.
     *
     * @return array{balance: numeric-string, date: string}|null
     */
    public function latest(int $bankAccountId): ?array
    {
        $punto = $this->lastKnownBalance($bankAccountId);

        return $punto === null ? null : ['balance' => $punto['balance'], 'date' => $punto['date']];
    }

    /**
     * El saldo al corte más lo movido hasta el último saldo informado, contra
     * ese saldo.
     *
     * Se compara en el mismo punto: los días anteriores enteros —su suma no
     * depende del orden— y, del día del último saldo, los movimientos que el
     * orden del banco pone antes o en él. Lo posterior, o lo que no se puede
     * ubicar, se cuenta aparte: todavía no se puede contrastar. Si no cierra,
     * falta un extracto entre el corte y ese día, o el saldo declarado no era
     * el del banco. No se corrige nada: se muestra.
     *
     * @return array{expected: numeric-string, latest: numeric-string, latestDate: string, difference: numeric-string, unverifiedCount: int}|null
     */
    public function reconcile(BankAccountOpening $corte): ?array
    {
        $corteDia = $corte->cutoff_on->toDateString();
        $punto = $this->lastKnownBalance($corte->bank_account_id);

        if ($punto === null || $punto['date'] <= $corteDia) {
            return null;
        }

        $despuesDelCorte = BankTransaction::query()
            ->where('bank_account_id', $corte->bank_account_id)
            ->where('transaction_date', '>', $corteDia);

        $contrastables = (clone $despuesDelCorte)
            ->where(fn (Builder $q) => $q->where('transaction_date', '<', $punto['date'])->orWhereIn('id', $punto['upTo']));

        $movido = (clone $contrastables)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'debit' THEN -amount ELSE amount END), 0) AS neto")
            ->value('neto');

        $esperado = Decimal::add($corte->statement_balance, Decimal::scale((string) ($movido ?? '0')));

        return [
            'expected' => $esperado,
            'latest' => $punto['balance'],
            'latestDate' => $punto['date'],
            'difference' => Decimal::sub($punto['balance'], $esperado),
            'unverifiedCount' => (clone $despuesDelCorte)->count() - (clone $contrastables)->count(),
        ];
    }

    /**
     * El último saldo que se puede afirmar, y los movimientos de su día que
     * van hasta él.
     *
     * Se busca del día más reciente hacia atrás. Un día cuyo orden los
     * extractos no demuestran (`BankDayOrder`) no da saldo: se sigue con el
     * anterior, y sus movimientos quedan sin contrastar. Dentro del día, el
     * último movimiento del orden bancario que trae saldo.
     *
     * @return array{balance: numeric-string, date: string, upTo: list<int>}|null
     */
    private function lastKnownBalance(int $bankAccountId): ?array
    {
        $dias = BankTransaction::query()
            ->where('bank_account_id', $bankAccountId)
            ->whereNotNull('balance_after')
            ->distinct()
            ->orderByDesc('transaction_date')
            ->pluck('transaction_date');

        foreach ($dias as $dia) {
            $fecha = CarbonImmutable::parse($dia)->toDateString();
            $orden = $this->orden->of($bankAccountId, $fecha);

            if ($orden === null) {
                continue;
            }

            for ($i = count($orden) - 1; $i >= 0; $i--) {
                if ($orden[$i]->balance_after !== null) {
                    return [
                        'balance' => $orden[$i]->balance_after,
                        'date' => $fecha,
                        'upTo' => array_map(fn (BankTransaction $m): int => $m->id, array_slice($orden, 0, $i + 1)),
                    ];
                }
            }
        }

        return null;
    }

    /** @return Builder<BankStatementRow> */
    private function rowsOf(int $importId): Builder
    {
        return BankStatementRow::query()
            ->where('bank_statement_import_id', $importId)
            ->where('parse_status', '<>', ParseStatus::Rejected->value)
            ->whereNotNull('parsed_transaction_date');
    }

    /**
     * El orden del extracto: fecha y, dentro del día, de abajo hacia arriba.
     *
     * @param  Builder<BankStatementRow>  $filas
     * @return Builder<BankStatementRow>
     */
    private function chronological(Builder $filas, bool $reverse = false): Builder
    {
        return $reverse
            ? $filas->orderByDesc('parsed_transaction_date')->orderBy('row_number')
            : $filas->orderBy('parsed_transaction_date')->orderByDesc('row_number');
    }

    /**
     * Lo que movió la fila, con signo. Nulo si el archivo no dejó leer su
     * importe: entonces no sirve para afirmar un saldo.
     *
     * @return numeric-string|null
     */
    private function signed(BankStatementRow $fila): ?string
    {
        $importe = $fila->parsed_amount;

        if ($importe === null || $fila->parsed_direction === null) {
            return null;
        }

        return $fila->parsed_direction === TransactionDirection::Debit ? Decimal::sub('0', $importe) : $importe;
    }
}
