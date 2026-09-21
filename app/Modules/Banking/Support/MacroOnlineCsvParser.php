<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Enums\ParseStatus;
use App\Modules\Banking\Enums\SourceFormat;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Support\Excel\SpreadsheetReader;
use App\Support\Money\Decimal;

/**
 * Lee el CSV que exporta MacroOnline.
 *
 * El archivo viene **doble comillado**: la línea entera está entre
 * comillas y las internas aparecen duplicadas.
 *
 * ```
 * "05/08/2026,""1145832449"",""4397"",""TRANSF 20111111112 VAR"",,""473.191,20"",""17.692.768,55"""
 * ```
 *
 * Un lector RFC 4180 hace bien su trabajo y devuelve **una sola columna**
 * con el contenido ya desenvuelto una vez. Este parser desenvuelve la
 * segunda. No es una peculiaridad menor: leerlo con un lector normal y no
 * darse cuenta produce catorce filas de una columna que nadie entiende.
 *
 * Lo que este formato **no** trae: número de cuenta, moneda, operador ni
 * fecha de descarga. Por eso un CSV no se puede verificar contra la
 * cuenta elegida y el Excel sí.
 */
final class MacroOnlineCsvParser implements StatementParser
{
    private const HEADER = 'FECHA';

    private const COLUMN_DATE = 0;

    private const COLUMN_REFERENCE = 1;

    private const COLUMN_CAUSAL = 2;

    private const COLUMN_DESCRIPTION = 3;

    private const COLUMN_DEBIT = 4;

    private const COLUMN_CREDIT = 5;

    private const COLUMN_BALANCE = 6;

    public function __construct(private readonly SpreadsheetReader $reader) {}

    public function format(): SourceFormat
    {
        return SourceFormat::MacroOnlineCsv;
    }

    public function version(): string
    {
        return 'macro-csv-1';
    }

    public function parse(string $path): ParsedStatement
    {
        $rows = [];
        $seenHeader = false;

        foreach ($this->reader->delimited($path) as $index => $line) {
            $fields = $this->unwrap($line);

            if ($fields === []) {
                continue;
            }

            if (! $seenHeader) {
                // La cabecera se reconoce por su primera celda; todo lo
                // anterior es ruido del archivo.
                if (mb_strtoupper(trim($fields[self::COLUMN_DATE])) === self::HEADER) {
                    $seenHeader = true;
                }

                continue;
            }

            $rows[] = $this->parseRow($index + 1, $fields);
        }

        return new ParsedStatement(
            format: $this->format(),
            rows: $rows,
        );
    }

    /**
     * Deshace el segundo nivel de comillado.
     *
     * `$line` es lo que devolvió el lector: una sola celda con la fila
     * entera adentro, o varias si el banco alguna vez arregla el archivo.
     *
     * @param  list<string>  $line
     * @return list<string>
     */
    private function unwrap(array $line): array
    {
        if ($line === []) {
            return [];
        }

        // Si el lector ya devolvió columnas separadas, el archivo vino
        // bien formado y no hay nada que desenvolver.
        if (count($line) > 1) {
            return array_map(trim(...), $line);
        }

        $raw = trim($line[0]);

        if ($raw === '') {
            return [];
        }

        $fields = str_getcsv($raw, ',', '"', '');

        return array_map(
            fn (?string $field): string => trim($field ?? ''),
            $fields,
        );
    }

    /**
     * @param  list<string>  $fields
     */
    private function parseRow(int $rowNumber, array $fields): ParsedRow
    {
        $date = $this->parseDate($fields[self::COLUMN_DATE] ?? '');

        if ($date === null) {
            return ParsedRow::rejected(
                $rowNumber,
                $fields,
                sprintf('La fecha «%s» no tiene el formato dd/mm/aaaa.', $fields[self::COLUMN_DATE] ?? ''),
            );
        }

        $debit = Decimal::parse($fields[self::COLUMN_DEBIT] ?? null);
        $credit = Decimal::parse($fields[self::COLUMN_CREDIT] ?? null);

        /*
         * El formato usa dos columnas excluyentes. Que vengan las dos o
         * ninguna no es una fila que se pueda interpretar de algún modo
         * razonable: es una fila que no se entiende.
         */
        if ($debit !== null && $credit !== null) {
            return ParsedRow::rejected($rowNumber, $fields, 'La fila informa débito y crédito a la vez.');
        }

        $amount = $debit ?? $credit;

        if ($amount === null || Decimal::equals($amount, '0.00')) {
            return ParsedRow::rejected($rowNumber, $fields, 'La fila no informa ningún importe.');
        }

        $direction = $debit !== null ? TransactionDirection::Debit : TransactionDirection::Credit;
        $description = $this->clean($fields[self::COLUMN_DESCRIPTION] ?? '', 300);
        $counterparty = Counterparty::fromDescription($description);
        $balance = Decimal::parse($fields[self::COLUMN_BALANCE] ?? null);

        /** @var numeric-string $magnitude */
        $magnitude = Decimal::abs($amount);

        return new ParsedRow(
            rowNumber: $rowNumber,
            raw: $fields,
            // Sin saldo posterior la fila no se puede deduplicar con
            // certeza contra otra descarga. Entra igual, señalada.
            status: $balance === null ? ParseStatus::Warning : ParseStatus::Valid,
            transactionDate: $date,
            amount: $magnitude,
            direction: $direction,
            operationId: $this->clean($fields[self::COLUMN_REFERENCE] ?? '', 40),
            causalCode: $this->clean($fields[self::COLUMN_CAUSAL] ?? '', 10),
            description: $description,
            counterpartyName: $counterparty->name,
            counterpartyIdentifier: $counterparty->identifier,
            balanceAfter: $balance,
        );
    }

    private function parseDate(string $value): ?string
    {
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', trim($value), $matches) !== 1) {
            return null;
        }

        [, $day, $month, $year] = $matches;

        return checkdate((int) $month, (int) $day, (int) $year)
            ? sprintf('%s-%s-%s', $year, $month, $day)
            : null;
    }

    private function clean(string $value, int $limit): ?string
    {
        $clean = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return $clean === '' ? null : mb_substr($clean, 0, $limit);
    }
}
