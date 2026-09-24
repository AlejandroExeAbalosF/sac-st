<?php

declare(strict_types=1);

namespace App\Support\Excel;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Lee solo las celdas dentro de un rectángulo que empieza en A1.
 *
 * Lo que queda afuera ni se carga en memoria: el tope vale también para lo
 * que pesa el libro, no solo para lo que se recorre después.
 */
final class BoundedReadFilter implements IReadFilter
{
    public function __construct(
        private readonly int $maxRows,
        private readonly int $maxColumns,
    ) {}

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return $row <= $this->maxRows
            && Coordinate::columnIndexFromString($columnAddress) <= $this->maxColumns;
    }
}
