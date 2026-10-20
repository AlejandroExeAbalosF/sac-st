<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Enums\ImportStatus;
use App\Modules\Banking\Enums\ParseStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementRow;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;

/**
 * Comprueba que el extracto encadene con lo que ya se importó.
 *
 * `BalanceChain` verifica que un archivo esté completo **por dentro**.
 * Esto verifica lo otro: que entre el último extracto y este no falte un
 * período.
 *
 * **Por qué importa acá y no en cualquier sistema.** El operador descarga
 * «Últimos movimientos» a mano. Si se saltea una semana e importa la
 * siguiente, un crédito faltante no es un dato perdido: es un empleador
 * que depositó, una cuota que nunca queda
 * financiada y un trabajador que no cobra sin que nadie sepa por qué.
 *
 * **Advierte, no rechaza.** Un salto no significa que este archivo esté
 * mal —está bien— sino que falta subir otro. Bloquearlo dejaría al
 * operador sin poder registrar un extracto legítimo por culpa de uno que
 * todavía no tiene.
 *
 * **La simplificación que lo vuelve barato.** El saldo posterior forma
 * parte de la huella del movimiento (§18 de las correcciones). Entonces,
 * si el archivo comparte aunque sea un movimiento con lo ya importado, la
 * continuidad queda probada por construcción y no hay nada que verificar.
 * Solo hace falta mirar cuando el archivo no toca nada de lo que ya está.
 *
 * **Lo conocido se lee de las filas de cada archivo, no de los
 * movimientos.** MacroOnline exporta lo más nuevo arriba, también dentro
 * de un día, y los movimientos entran a la base en el orden del archivo:
 * dentro del último día, el `id` más alto es el movimiento más viejo y su
 * saldo no es el de cierre. Las filas conservan su posición en el archivo,
 * y con ella el orden cronológico real —el de
 * `ParsedStatement::chronologicalRows`—. Entre archivos del mismo día manda
 * el orden que demuestran sus movimientos compartidos (`BankDayOrder`), no
 * cuál se importó último; si no lo demuestran, no se afirma ningún salto.
 */
final class StatementContinuity
{
    public static function verify(
        ParsedStatement $statement,
        BankAccount $account,
        bool $overlapsKnownTransactions,
    ): ?string {
        // Hay solapamiento: las huellas coinciden y el saldo está adentro
        // de la huella. No queda nada por comprobar.
        if ($overlapsKnownTransactions) {
            return null;
        }

        $ordenadas = $statement->chronologicalRows();

        if ($ordenadas === []) {
            return null;
        }

        return self::gapBefore($ordenadas[0], $statement, $account)
            ?? self::gapAfter($ordenadas[count($ordenadas) - 1], $statement, $account);
    }

    /**
     * ¿Falta algo entre el último movimiento conocido y el primero de este
     * archivo?
     *
     * Es el caso normal: se importa hacia adelante y se saltea un período.
     */
    private static function gapBefore(
        ParsedRow $primera,
        ParsedStatement $statement,
        BankAccount $account,
    ): ?string {
        $apertura = $statement->openingBalance();

        if ($apertura === null || $primera->transactionDate === null) {
            return null;
        }

        $dia = self::knownDay($account, '<', $primera->transactionDate);

        // El último movimiento de ese día en el orden del banco. Si los
        // extractos no lo demuestran, no se afirma un salto.
        $orden = $dia === null ? null : (new BankDayOrder)->of($account->id, $dia);
        $anterior = $orden === null || $orden === [] ? null : $orden[count($orden) - 1];

        if ($anterior === null || $anterior->balance_after === null) {
            return null;
        }

        if (Decimal::equals($anterior->balance_after, $apertura)) {
            return null;
        }

        return self::describe(
            desde: self::formatDate((string) $dia),
            hasta: self::formatDate($primera->transactionDate),
            conocido: $anterior->balance_after,
            esperado: $apertura,
        );
    }

    /**
     * ¿Y si el archivo es anterior a todo lo que ya está?
     *
     * Pasa cuando se importa un extracto viejo después de uno nuevo. La
     * comprobación es la misma al revés: el saldo con el que cierra este
     * archivo tiene que ser el que había antes del movimiento más antiguo
     * que el sistema ya conoce.
     */
    private static function gapAfter(
        ParsedRow $ultima,
        ParsedStatement $statement,
        BankAccount $account,
    ): ?string {
        $cierre = $statement->closingBalance();

        if ($cierre === null || $ultima->transactionDate === null) {
            return null;
        }

        $dia = self::knownDay($account, '>', $ultima->transactionDate);

        // El primer movimiento de ese día en el orden del banco. Si los
        // extractos no lo demuestran, no se afirma un salto.
        $orden = $dia === null ? null : (new BankDayOrder)->of($account->id, $dia);
        $siguiente = $orden === null || $orden === [] ? null : $orden[0];

        if ($siguiente === null || $siguiente->balance_after === null) {
            return null;
        }

        // El saldo que había antes de ese movimiento: su saldo posterior
        // menos lo que el movimiento cambió.
        $movimiento = $siguiente->direction === TransactionDirection::Debit
            ? Decimal::sub('0', $siguiente->amount)
            : $siguiente->amount;

        $previoDelSiguiente = Decimal::sub($siguiente->balance_after, $movimiento);

        if (Decimal::equals($previoDelSiguiente, $cierre)) {
            return null;
        }

        return self::describe(
            desde: self::formatDate($ultima->transactionDate),
            hasta: self::formatDate((string) $dia),
            conocido: $cierre,
            esperado: $previoDelSiguiente,
        );
    }

    /**
     * El día conocido más cercano antes o después de una fecha: el último
     * anterior (`<`) o el primero posterior (`>`) que traen los extractos ya
     * importados de la cuenta.
     */
    /** @param  '<'|'>'  $sentido */
    private static function knownDay(BankAccount $account, string $sentido, string $fecha): ?string
    {
        $filas = BankStatementRow::query()
            ->join('bank_statement_imports', 'bank_statement_imports.id', '=', 'bank_statement_rows.bank_statement_import_id')
            ->where('bank_statement_imports.bank_account_id', $account->id)
            ->where('bank_statement_imports.status', ImportStatus::Completed->value)
            ->where('bank_statement_rows.parse_status', '<>', ParseStatus::Rejected->value)
            ->whereNotNull('bank_statement_rows.bank_transaction_id')
            ->where('bank_statement_rows.parsed_transaction_date', $sentido, $fecha);

        $dia = $sentido === '<'
            ? $filas->max('bank_statement_rows.parsed_transaction_date')
            : $filas->min('bank_statement_rows.parsed_transaction_date');

        return $dia === null ? null : CarbonImmutable::parse((string) $dia)->toDateString();
    }

    /**
     * El mensaje dice el importe de la diferencia, no solo que hay una.
     *
     * Quien lo lee tiene que salir a buscar un extracto: saber cuánto
     * falta es lo que le permite reconocerlo cuando lo encuentre.
     */
    private static function describe(
        string $desde,
        string $hasta,
        string $conocido,
        string $esperado,
    ): string {
        $diferencia = Decimal::format(Decimal::abs(Decimal::sub($esperado, $conocido)));

        return sprintf(
            'Entre el %s y el %s el saldo no encadena: hay una diferencia de $%s. '
            .'Probablemente falte importar el extracto de ese período.',
            $desde,
            $hasta,
            $diferencia,
        );
    }

    private static function formatDate(string $isoDate): string
    {
        $partes = explode('-', $isoDate);

        return count($partes) === 3
            ? sprintf('%s/%s/%s', $partes[2], $partes[1], $partes[0])
            : $isoDate;
    }
}
