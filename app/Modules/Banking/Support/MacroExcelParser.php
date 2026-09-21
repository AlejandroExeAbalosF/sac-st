<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Enums\ParseStatus;
use App\Modules\Banking\Enums\SourceFormat;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Support\Excel\SpreadsheetReader;
use App\Support\Money\Decimal;

/**
 * Lee el Excel que exporta MacroOnline.
 *
 * Trae menos rarezas que el CSV y bastante más información. La hoja tiene
 * tres partes:
 *
 * ```
 * Tipo                 Cuenta Corriente          ← cabecera
 * Número               310000123456789
 * Moneda               PESOS
 * Fecha  …  Nro. de Referencia  Causal  Concepto  Importe  …  Saldo
 * 05/08/2026 …  1145832449  4397  TRANSF …  473191.2  …  17692768.55
 * Fecha de descarga: 06/08/2026 11:24:11                    ← pie
 * Empresa: 30222222229 - MINISTERIO DE GOBIERNO …
 * Operador: ANA MARIA GONZALEZ
 * ```
 *
 * Dos diferencias con el CSV que importan:
 *
 * 1. **El importe es uno solo, con signo**: negativo es débito.
 * 2. **La cabecera identifica la cuenta y la moneda**, que es lo único
 *    que permite rechazar el extracto de otra cuenta subido por error.
 *
 * Las columnas no son contiguas: la hoja usa celdas combinadas y deja
 * huecos en 1, 2, 7, 8 y 9. Las posiciones son las del archivo real, no
 * un orden ideal.
 */
final class MacroExcelParser implements StatementParser
{
    private const COLUMN_DATE = 0;

    private const COLUMN_REFERENCE = 3;

    private const COLUMN_CAUSAL = 4;

    private const COLUMN_DESCRIPTION = 5;

    private const COLUMN_AMOUNT = 6;

    private const COLUMN_BALANCE = 10;

    /** @var array<string, string> Cómo nombra el banco a la moneda. */
    private const CURRENCIES = [
        'PESOS' => 'ARS',
        'PESO' => 'ARS',
        'DOLARES' => 'USD',
        'DÓLARES' => 'USD',
        'DOLAR' => 'USD',
    ];

    public function __construct(private readonly SpreadsheetReader $reader) {}

    public function format(): SourceFormat
    {
        return SourceFormat::MacroExcel;
    }

    public function version(): string
    {
        return 'macro-xls-1';
    }

    public function parse(string $path): ParsedStatement
    {
        $lines = $this->reader->spreadsheet($path);

        $accountNumber = null;
        $currency = null;
        $downloadedAt = null;
        $operator = null;
        $rows = [];
        $inBody = false;

        foreach ($lines as $index => $line) {
            $first = trim($line[self::COLUMN_DATE] ?? '');

            if (! $inBody) {
                // La cabecera son pares etiqueta/valor con una columna
                // vacía en el medio por la celda combinada.
                $label = mb_strtoupper($first);
                $value = trim($line[2] ?? '');

                if ($label === 'NÚMERO' || $label === 'NUMERO') {
                    $accountNumber = $value === '' ? null : $value;
                }

                if ($label === 'MONEDA') {
                    $currency = self::CURRENCIES[mb_strtoupper($value)] ?? null;
                }

                if ($label === 'FECHA' && trim($line[self::COLUMN_AMOUNT] ?? '') !== '') {
                    $inBody = true;
                }

                continue;
            }

            // El pie corta el cuerpo: son textos sueltos en la primera
            // columna, sin importe.
            if (str_starts_with(mb_strtoupper($first), 'FECHA DE DESCARGA')) {
                $downloadedAt = $this->parseDownloadedAt($first);
                $inBody = false;

                continue;
            }

            if ($first === '' && trim($line[self::COLUMN_AMOUNT] ?? '') === '') {
                continue;
            }

            $rows[] = $this->parseRow($index + 1, $line);
        }

        foreach ($lines as $line) {
            $first = trim($line[self::COLUMN_DATE] ?? '');

            if (str_starts_with(mb_strtoupper($first), 'OPERADOR:')) {
                $operator = trim(mb_substr($first, strlen('Operador:'))) ?: null;
            }

            if ($downloadedAt === null && str_starts_with(mb_strtoupper($first), 'FECHA DE DESCARGA')) {
                $downloadedAt = $this->parseDownloadedAt($first);
            }
        }

        return new ParsedStatement(
            format: $this->format(),
            rows: $rows,
            accountNumber: $accountNumber,
            currency: $currency,
            downloadedAt: $downloadedAt,
            operator: $operator === null ? null : mb_substr($operator, 0, 160),
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
                sprintf('La fecha «%s» no se pudo interpretar.', $fields[self::COLUMN_DATE] ?? ''),
            );
        }

        $amount = Decimal::parse($fields[self::COLUMN_AMOUNT] ?? null);

        if ($amount === null || Decimal::equals($amount, '0.00')) {
            return ParsedRow::rejected($rowNumber, $fields, 'La fila no informa ningún importe.');
        }

        // Acá el signo ES la dirección: el archivo trae una sola columna.
        $direction = Decimal::isNegative($amount)
            ? TransactionDirection::Debit
            : TransactionDirection::Credit;

        $description = $this->clean($fields[self::COLUMN_DESCRIPTION] ?? '', 300);
        $counterparty = Counterparty::fromDescription($description);
        $balance = Decimal::parse($fields[self::COLUMN_BALANCE] ?? null);

        /** @var numeric-string $magnitude */
        $magnitude = Decimal::abs($amount);

        return new ParsedRow(
            rowNumber: $rowNumber,
            raw: $fields,
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

    /**
     * El lector ya resolvió el serial de Excel a `Y-m-d`; queda aceptar
     * también `dd/mm/aaaa` por si una celda vino como texto.
     */
    private function parseDate(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $matches) === 1) {
            [, $year, $month, $day] = $matches;

            return checkdate((int) $month, (int) $day, (int) $year)
                ? sprintf('%s-%s-%s', $year, $month, $day)
                : null;
        }

        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $value, $matches) === 1) {
            [, $day, $month, $year] = $matches;

            return checkdate((int) $month, (int) $day, (int) $year)
                ? sprintf('%s-%s-%s', $year, $month, $day)
                : null;
        }

        return null;
    }

    private function parseDownloadedAt(string $line): ?string
    {
        if (preg_match('#(\d{2})/(\d{2})/(\d{4})\s+(\d{2}):(\d{2}):(\d{2})#', $line, $m) !== 1) {
            return null;
        }

        [, $day, $month, $year, $hour, $minute, $second] = $m;

        return checkdate((int) $month, (int) $day, (int) $year)
            ? sprintf('%s-%s-%s %s:%s:%s', $year, $month, $day, $hour, $minute, $second)
            : null;
    }

    private function clean(string $value, int $limit): ?string
    {
        $clean = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return $clean === '' ? null : mb_substr($clean, 0, $limit);
    }
}
