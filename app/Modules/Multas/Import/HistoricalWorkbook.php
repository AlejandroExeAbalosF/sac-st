<?php

declare(strict_types=1);

namespace App\Modules\Multas\Import;

use App\Support\Excel\SpreadsheetReader;
use RuntimeException;

/**
 * La planilla de la cuenta corriente de Multas, hoja por hoja.
 *
 * Solo las hojas de año (2007–2026): los planes de pago, «OTROS» y las
 * resoluciones ministeriales quedan para más adelante (decisión del usuario,
 * 11/11/2026). Cada hoja se lee sola, para no tener la planilla entera en
 * memoria.
 */
final class HistoricalWorkbook
{
    public const VERSION = 'multas-xls-1';

    /** La hoja más ancha trae 148 columnas. */
    private const MAX_COLUMNS = 200;

    public function __construct(private readonly SpreadsheetReader $reader) {}

    /**
     * @return list<string>
     */
    public function yearSheets(string $path): array
    {
        return array_values(array_filter(
            $this->reader->sheetNames($path),
            fn (string $nombre): bool => preg_match('~^\d{4}$~', trim($nombre)) === 1,
        ));
    }

    /**
     * La disposición de la hoja y sus filas de datos, desde la 2.
     *
     * @return array{layout: SheetLayout, rows: list<array{row: int, cells: array<string, array{value: string, formula: string|null, missing: bool}>}>}
     */
    public function read(string $path, string $sheet): array
    {
        $filas = $this->reader->sheetCells($path, $sheet, self::MAX_COLUMNS);

        $encabezado = [];

        foreach ($filas[0]['cells'] ?? [] as $letra => $celda) {
            $encabezado[$letra] = $celda['value'];
        }

        if (($filas[0]['row'] ?? 0) !== 1 || ($layout = SheetLayout::detect($encabezado)) === null) {
            throw new RuntimeException("La hoja {$sheet} no tiene en la fila 1 los encabezados EXPTE., RESOL., CARATULA e IMPORTE.");
        }

        return ['layout' => $layout, 'rows' => array_slice($filas, 1)];
    }
}
