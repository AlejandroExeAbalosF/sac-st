<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Modules\Banking\Enums\SourceFormat;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Support\BalanceChain;
use App\Modules\Banking\Support\ParsedRow;
use App\Modules\Banking\Support\ParsedStatement;
use App\Modules\Banking\Support\StatementContinuity;
use App\Modules\Banking\Support\StatementParserFactory;
use App\Modules\Banking\Support\StatementPreview;
use App\Modules\Banking\Support\TransactionFingerprint;

/**
 * Lee un extracto y dice qué pasaría si se importara. No escribe nada.
 *
 * Los tres controles que corre son los que el archivo real hizo evidentes:
 *
 * 1. **La cuenta.** El Excel declara el número de cuenta en su cabecera.
 *    Subir el extracto de otra cuenta es un error trivial de cometer y
 *    catastrófico de no detectar: mezclaría los movimientos de dos cuentas
 *    en la misma conciliación.
 * 2. **La moneda.** Hoy todo es ARS, pero se prevé una cuenta en dólares y
 *    §4.4 del DER es terminante: nunca se suman importes de monedas
 *    distintas. El control tiene que existir antes que la cuenta, no
 *    después.
 * 3. **La cadena de saldos.** Si `saldo[n] ≠ saldo[n-1] + importe[n]`,
 *    falta un movimiento en el archivo.
 *
 * Ninguno de los tres es una advertencia. Los tres rechazan el archivo.
 */
final class ParseBankStatement
{
    public function __construct(private readonly StatementParserFactory $parsers) {}

    public function handle(string $path, BankAccount $account, SourceFormat $format): StatementPreview
    {
        $parser = $this->parsers->for($format);
        $statement = $parser->parse($path);

        $problems = $this->inspect($statement, $account);
        $chain = BalanceChain::verify($statement);

        if ($chain->isValid === false && $chain->failure !== null) {
            $problems[] = $chain->failure;
        }

        if ($statement->usableRows() === []) {
            $problems[] = 'El archivo no contiene ningún movimiento que se pueda interpretar.';
        }

        [$nuevas, $conocidas, $filasRepetidas] = $this->classify($statement, $account);

        /*
         * La continuidad advierte, no rechaza. Un salto no dice que este
         * archivo esté mal —está bien— sino que falta subir otro, y
         * bloquearlo dejaría al operador sin poder registrar un extracto
         * legítimo por culpa de uno que todavía no tiene.
         */
        $continuidad = $problems === []
            ? StatementContinuity::verify($statement, $account, $conocidas !== [])
            : null;

        return new StatementPreview(
            statement: $statement,
            chain: $chain,
            problems: $problems,
            newFingerprints: $nuevas,
            knownFingerprints: $conocidas,
            repeatedRowNumbers: $filasRepetidas,
            parserVersion: $parser->version(),
            continuityWarning: $continuidad,
        );
    }

    /**
     * @return list<string>
     */
    private function inspect(ParsedStatement $statement, BankAccount $account): array
    {
        $problems = [];

        /*
         * Solo se compara cuando los dos lados tienen el dato: el CSV de
         * MacroOnline no declara la cuenta, y exigirla dejaría afuera un
         * formato que el área usa. Lo que no se puede es dejar pasar una
         * cuenta que sí vino y no coincide.
         */
        if ($statement->accountNumber !== null
            && $account->account_number !== null
            && $this->onlyDigits($statement->accountNumber) !== $this->onlyDigits($account->account_number)
        ) {
            $problems[] = sprintf(
                'El extracto es de la cuenta %s y se está importando sobre %s (%s).',
                $statement->accountNumber,
                $account->account_number,
                $account->label,
            );
        }

        if ($statement->currency !== null && $statement->currency !== $account->currency) {
            $problems[] = sprintf(
                'El extracto está expresado en %s y la cuenta opera en %s. No se mezclan monedas.',
                $statement->currency,
                $account->currency,
            );
        }

        return $problems;
    }

    /**
     * Separa lo que ya está en el sistema de lo que llegaría por primera vez.
     *
     * Un movimiento repetido no es un problema, es lo esperable: el
     * operador descarga rangos que se pisan. Saber cuántos son antes de
     * confirmar es lo que evita que sospeche de la importación al ver que
     * el saldo no se movió.
     *
     * **También devuelve qué filas son las repetidas**, no solo cuántas.
     * Un total de «12 repetidos» sin decir cuáles obliga a comparar el
     * archivo contra la pantalla a ojo, que es exactamente el trabajo que
     * el sistema viene a sacar de encima.
     *
     * @return array{0: list<string>, 1: list<string>, 2: list<int>}
     */
    private function classify(ParsedStatement $statement, BankAccount $account): array
    {
        /** @var array<string, list<int>> $porHuella huella verificable => filas que la traen */
        $porHuella = [];
        /** @var list<string> $sinSaldo filas que no se deduplican automáticamente */
        $sinSaldo = [];

        foreach ($statement->usableRows() as $row) {
            $fingerprint = self::fingerprintOf($row, $account);

            if ($fingerprint !== null) {
                if ($row->balanceAfter === null) {
                    $sinSaldo[] = $fingerprint.'#fila-'.$row->rowNumber;

                    continue;
                }

                $porHuella[$fingerprint][] = $row->rowNumber;
            }
        }

        if ($porHuella === []) {
            return [$sinSaldo, [], []];
        }

        /** @var list<string> $existing */
        $existing = BankTransaction::query()
            ->where('bank_account_id', $account->id)
            ->whereIn('fingerprint', array_keys($porHuella))
            ->pluck('fingerprint')
            ->all();

        $known = array_values(array_unique($existing));
        $new = [...array_values(array_diff(array_keys($porHuella), $known)), ...$sinSaldo];

        $repetidas = [];

        foreach ($porHuella as $fingerprint => $filas) {
            $yaEstaba = in_array($fingerprint, $known, true);

            foreach ($filas as $indice => $rowNumber) {
                /*
                 * Repetida por dos motivos distintos que para el operador
                 * significan lo mismo —ese movimiento no se va a crear—:
                 * o ya venía de otra importación, o es la segunda vez que
                 * aparece dentro de este mismo archivo.
                 */
                if ($yaEstaba || $indice > 0) {
                    $repetidas[] = $rowNumber;
                }
            }
        }

        sort($repetidas);

        return [$new, $known, $repetidas];
    }

    public static function fingerprintOf(ParsedRow $row, BankAccount $account): ?string
    {
        if ($row->transactionDate === null || $row->direction === null || $row->amount === null) {
            return null;
        }

        return TransactionFingerprint::of(
            $account->id,
            $row->transactionDate,
            $row->direction,
            $row->amount,
            $row->operationId,
            $row->description,
            $row->balanceAfter,
        );
    }

    private function onlyDigits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
