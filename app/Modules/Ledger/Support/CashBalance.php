<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventStatus;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Los saldos, calculados desde el sublibro.
 *
 * **Ningún saldo se almacena** (§5.1 del DER: *«Los saldos se calculan; no
 * son contadores editables»*). Un contador puede desincronizarse y nadie
 * se entera hasta que el arqueo no cierra; una suma sobre líneas inmutables
 * no puede mentir.
 *
 * Es la única puerta de lectura del libro, igual que `PostJournalEntry` es
 * la única de escritura. Que el saldo teórico del arqueo y el saldo que la
 * pantalla muestra salgan de acá es lo que garantiza que digan lo mismo.
 *
 * Todo se pide **por caja y por moneda**, sin excepción. Un saldo sin
 * moneda es la suma de pesos con dólares que la corrección 23 vino a
 * evitar; uno sin caja no dice de qué caja es, y `journal_lines` lleva esa
 * dimensión en cada fila desde la Etapa 2.
 */
final class CashBalance
{
    /**
     * El saldo de una cuenta, en su signo natural.
     *
     * Las cuentas de ubicación —caja, banco, tránsito— crecen por el
     * débito: la plata entra al cajón. Las de atribución —fondos sin
     * identificar, fondos de beneficiarios— crecen por el crédito: alguien
     * pasa a ser dueño de algo. Devolver el signo natural de cada una
     * evita que quien la consulte tenga que acordarse de cuál es cuál.
     *
     * `$upTo` es **la fecha operativa del evento**, no la de escritura: un
     * arqueo del 30/06 cuenta lo que pasó hasta el 30/06 aunque se haya
     * cargado el 2 de julio.
     *
     * @return numeric-string
     */
    public function of(
        LedgerAccount $account,
        int $cashBoxId,
        Currency $currency = Currency::Ars,
        ?CarbonInterface $upTo = null,
    ): string {
        $fila = $this->lines($cashBoxId, $currency)
            ->where('journal_lines.account_code', $account->value)
            ->when(
                $upTo !== null,
                fn (Builder $query): Builder => $query->whereDate('financial_events.event_date', '<=', $upTo)
            )
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) AS debito')
            ->selectRaw('COALESCE(SUM(journal_lines.credit), 0) AS credito')
            ->first();

        $debito = Decimal::scale((string) ($fila->debito ?? '0'));
        $credito = Decimal::scale((string) ($fila->credito ?? '0'));

        return $account->isLocation()
            ? Decimal::sub($debito, $credito)
            : Decimal::sub($credito, $debito);
    }

    /**
     * Lo que se movió en una cuenta durante un período, abierto por hecho.
     *
     * Es lo que la planilla necesita y un saldo no da: el anverso separa
     * `INGRESOS` de `EGRESOS` y de `DEPOSITOS BANCO MACRO`, y las tres son
     * créditos y débitos sobre la misma cuenta `CASH_ON_HAND`. Sin saber
     * **de qué hecho** viene cada línea, las tres filas serían una sola.
     *
     * **La reversión se imputa al tipo que revierte.** Un cobro anulado
     * resta de `INGRESOS`; si quedara en un renglón propio, la planilla
     * mostraría un ingreso que no existió y un «revertido» que el papel no
     * tiene.
     *
     * @return array<string, array{debit: numeric-string, credit: numeric-string}>
     *                                                                             Indexado por el tipo de evento efectivo.
     */
    public function movementsByEvent(
        LedgerAccount $account,
        int $cashBoxId,
        CarbonInterface $from,
        CarbonInterface $to,
        Currency $currency = Currency::Ars,
    ): array {
        $filas = $this->lines($cashBoxId, $currency)
            ->leftJoin(
                'financial_events AS revertido',
                'revertido.id', '=', 'financial_events.reversal_of_id'
            )
            ->where('journal_lines.account_code', $account->value)
            ->whereDate('financial_events.event_date', '>=', $from)
            ->whereDate('financial_events.event_date', '<=', $to)
            ->selectRaw('COALESCE(revertido.event_type, financial_events.event_type) AS hecho')
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) AS debito')
            ->selectRaw('COALESCE(SUM(journal_lines.credit), 0) AS credito')
            ->groupBy('hecho')
            ->get();

        $movimientos = [];

        foreach ($filas as $fila) {
            $movimientos[(string) $fila->hecho] = [
                'debit' => Decimal::scale((string) $fila->debito),
                'credit' => Decimal::scale((string) $fila->credito),
            ];
        }

        return $movimientos;
    }

    /**
     * Si la caja tuvo algún movimiento asentado en el período.
     *
     * Un día sin movimientos igual se cierra —la planilla de junio tiene
     * varios—, pero saberlo permite que la pantalla lo diga en vez de
     * mostrar una grilla vacía sin explicación.
     */
    public function hasMovements(
        int $cashBoxId,
        CarbonInterface $from,
        CarbonInterface $to,
        Currency $currency = Currency::Ars,
    ): bool {
        return $this->lines($cashBoxId, $currency)
            ->whereDate('financial_events.event_date', '>=', $from)
            ->whereDate('financial_events.event_date', '<=', $to)
            ->exists();
    }

    /**
     * El saldo acumulado de una cuenta, día por día.
     *
     * **Una sola consulta para todas las fechas.** Comprobar si ciento
     * veinte cierres siguen coincidiendo con el libro llamando a `of()`
     * por cada uno serían ciento veinte consultas; acá se traen los netos
     * por día y el acumulado se arma recorriéndolos una vez.
     *
     * Solo aparecen los días con movimiento: para saber el saldo de un
     * día sin asientos se toma el último anterior, que es lo que hace
     * `balanceOn()`.
     *
     * @return array<string, numeric-string> Indexado por `Y-m-d`.
     */
    public function runningTotals(LedgerAccount $account, int $cashBoxId, Currency $currency = Currency::Ars): array
    {
        $filas = $this->lines($cashBoxId, $currency)
            ->where('journal_lines.account_code', $account->value)
            ->groupBy('financial_events.event_date')
            ->orderBy('financial_events.event_date')
            ->get([
                DB::raw('financial_events.event_date AS dia'),
                DB::raw('COALESCE(SUM(journal_lines.debit), 0) AS debito'),
                DB::raw('COALESCE(SUM(journal_lines.credit), 0) AS credito'),
            ]);

        $acumulado = '0.00';
        $porDia = [];

        foreach ($filas as $fila) {
            $neto = Decimal::sub(
                Decimal::scale((string) $fila->debito),
                Decimal::scale((string) $fila->credito),
            );

            $acumulado = $account->isLocation()
                ? Decimal::add($acumulado, $neto)
                : Decimal::sub($acumulado, $neto);

            $porDia[CarbonImmutable::parse((string) $fila->dia)->toDateString()] = $acumulado;
        }

        return $porDia;
    }

    /**
     * El saldo a una fecha, leído de un acumulado ya traído.
     *
     * @param  array<string, numeric-string>  $running
     * @return numeric-string
     */
    public static function balanceOn(array $running, string $date): string
    {
        $saldo = '0.00';

        foreach ($running as $dia => $acumulado) {
            if ($dia > $date) {
                break;
            }

            $saldo = $acumulado;
        }

        return $saldo;
    }

    /**
     * Las líneas que cuentan: asentadas, de esta caja y de esta moneda.
     *
     * Un borrador no pesa en ningún saldo: es un asiento a medio escribir.
     * Un evento revertido sí pesa: la reversión no borra el original, crea
     * otro asiento que lo resta. Excluir el original dejaría esa resta sin
     * minuendo y falsearía el saldo.
     */
    private function lines(int $cashBoxId, Currency $currency): Builder
    {
        return DB::table('journal_lines')
            ->join(
                'financial_events',
                'financial_events.id', '=', 'journal_lines.financial_event_id'
            )
            ->whereIn('financial_events.status', [
                FinancialEventStatus::Posted->value,
                FinancialEventStatus::Reversed->value,
            ])
            ->where('journal_lines.cash_box_id', $cashBoxId)
            ->where('journal_lines.currency', $currency->value);
    }
}
