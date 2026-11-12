<?php

declare(strict_types=1);

namespace App\Support\Excel;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Ods;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

/**
 * Abre una planilla y devuelve sus filas como texto. Nada más.
 *
 * Es infraestructura sin dominio: no sabe qué es un extracto bancario ni
 * qué banco lo emitió. Interpretar esas filas es trabajo del parser del
 * módulo, que sí conoce el formato.
 *
 * **Todas las celdas salen como `string`.** Un `.xls` guarda sus números
 * como doubles IEEE754 —es lo que el formato BIFF8 tiene adentro, no una
 * decisión nuestra—, así que el `float` es inevitable al abrir el archivo.
 * Lo que sí se decide acá es que muera en esta clase: se convierte a
 * decimal en el mismo paso que lo lee y de esta puerta para adentro del
 * sistema no vuelve a existir.
 */
final class SpreadsheetReader
{
    /**
     * Seis decimales alcanzan para cualquier importe bancario y son los que
     * corrigen la representación binaria: el `1253491.0499999999` que
     * devuelve el double vuelve a ser `1253491.05` al redondear acá.
     */
    private const NUMERIC_PRECISION = 6;

    /*
     * El tamaño máximo de hoja que se lee.
     *
     * Un extracto trae cientos de filas y once columnas. Sin tope, un
     * archivo de pocos KB con una celda en la última fila y la última
     * columna de la hoja obligaba a recorrer diecisiete mil millones de
     * celdas vacías, y cada subida mataba un proceso del servidor. Las
     * columnas de más se descartan sin decir nada —son las que Excel
     * formateó alguna vez—; las filas con datos más allá del tope, no:
     * eso ya no es un extracto.
     */
    public const MAX_ROWS = 20_000;

    public const MAX_COLUMNS = 60;

    /**
     * @param  int  $maxRows  El tope de filas. Se inyecta para que los tests
     *                        lo prueben sin armar una planilla de veinte mil.
     */
    public function __construct(private readonly int $maxRows = self::MAX_ROWS) {}

    /**
     * Filas de un archivo delimitado —CSV o TSV—, como texto.
     *
     * Las filas conservan su largo original porque el parser identifica
     * cada dato por su posición.
     *
     * @return list<list<string>>
     */
    public function delimited(string $path): array
    {
        $this->assertReadable($path);

        return $this->readDelimited($path);
    }

    /**
     * Filas de la primera hoja de una planilla —xls, xlsx u ods—.
     *
     * @return list<list<string>>
     */
    public function spreadsheet(string $path): array
    {
        $this->assertReadable($path);

        return $this->readSpreadsheet($path);
    }

    /**
     * Los nombres de las hojas de una planilla, en orden.
     *
     * @return list<string>
     */
    public function sheetNames(string $path): array
    {
        $this->assertReadable($path);

        return array_values($this->readerFor($path)->listWorksheetNames($path));
    }

    /**
     * Las celdas con datos de una hoja, sin materializar las vacías.
     *
     * Para planillas grandes y con fórmulas, como la cuenta corriente de un
     * área: una hoja de ocho mil filas por noventa columnas, recorrida celda
     * por celda, ocupa más de un gigabyte; leyendo solo las que existen, cien
     * megas. De una fórmula sale el **resultado que guardó Excel**, con la
     * fórmula al lado; si el archivo no guardó ese resultado, se dice.
     *
     * @return list<array{row: int, cells: array<string, array{value: string, formula: string|null, missing: bool}>}>
     */
    public function sheetCells(string $path, string $sheet, int $maxColumns): array
    {
        $this->assertReadable($path);

        $reader = $this->readerFor($path);
        $reader->setLoadSheetsOnly([$sheet]);
        $reader->setReadEmptyCells(false);
        $reader->setReadFilter(new BoundedReadFilter($this->maxRows + 1, $maxColumns));

        $book = $reader->load($path);
        $hoja = $book->getActiveSheet();
        $filas = [];

        foreach ($hoja->getRowIterator() as $fila) {
            $iterador = $fila->getCellIterator();
            $iterador->setIterateOnlyExistingCells(true);
            $celdas = [];

            foreach ($iterador as $celda) {
                $formula = $celda->isFormula() ? (string) $celda->getValue() : null;

                if ($formula !== null) {
                    $guardado = $celda->getOldCalculatedValue();
                    $valor = match (true) {
                        $guardado === null || $guardado === '' => '',
                        is_int($guardado) || is_float($guardado) => $this->toDecimalString((float) $guardado),
                        is_bool($guardado) => $guardado ? '1' : '0',
                        default => $this->toUtf8(trim(is_scalar($guardado) ? (string) $guardado : '')),
                    };
                    $celdas[$celda->getColumn()] = ['value' => $valor, 'formula' => $formula, 'missing' => $guardado === null];

                    continue;
                }

                $valor = $this->cellToString($celda);

                if ($valor !== '') {
                    $celdas[$celda->getColumn()] = ['value' => $valor, 'formula' => null, 'missing' => false];
                }
            }

            if ($celdas !== []) {
                $filas[] = ['row' => $fila->getRowIndex(), 'cells' => $celdas];
            }
        }

        $book->disconnectWorksheets();

        if ($filas !== [] && $filas[count($filas) - 1]['row'] > $this->maxRows) {
            throw new RuntimeException(sprintf('La hoja %s tiene más de %s filas con datos.', $sheet, number_format($this->maxRows, 0, ',', '.')));
        }

        return $filas;
    }

    private function readerFor(string $path): Xls|Xlsx|Ods
    {
        // Por contenido, no por extensión: ver `readSpreadsheet()`.
        $reader = IOFactory::createReader(IOFactory::identify($path, [
            IOFactory::READER_XLS,
            IOFactory::READER_XLSX,
            IOFactory::READER_ODS,
        ]));

        if (! $reader instanceof Xls && ! $reader instanceof Xlsx && ! $reader instanceof Ods) {
            throw new RuntimeException('El archivo no es una planilla de Excel ni de OpenOffice.');
        }

        return $reader;
    }

    private function assertReadable(string $path): void
    {
        if (! is_readable($path)) {
            throw new RuntimeException("No se puede leer el archivo: {$path}.");
        }
    }

    /**
     * Lee un delimitado con `fgetcsv`, no con PhpSpreadsheet.
     *
     * El lector de PhpSpreadsheet infiere tipos y convierte lo que le
     * parece una fecha o un número. En un CSV bancario eso es exactamente
     * lo que no se quiere: el texto tiene que llegar al parser tal como el
     * banco lo escribió, incluidas sus rarezas.
     *
     * @return list<list<string>>
     */
    private function readDelimited(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("No se pudo abrir el archivo: {$path}.");
        }

        $rows = [];

        try {
            /*
             * Excel («CSV UTF-8») y el Bloc de notas anteponen la marca BOM
             * al guardar. Delante de la primera comilla, esa marca hace que
             * la primera celda deje de leerse como entrecomillada y la
             * cabecera ya no se reconoce: un extracto abierto y guardado
             * otra vez se rechazaba sin ningún movimiento.
             */
            if (fread($handle, 3) !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            // Escape vacío: RFC 4180 puro. La barra invertida no escapa
            // nada en un CSV bancario, y tratarla como si lo hiciera
            // rompe cualquier concepto que la contenga.
            while (($fields = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                // fgetcsv devuelve [null] en una línea vacía.
                if ($fields === [null]) {
                    $rows[] = [];

                    continue;
                }

                $rows[] = array_map(
                    fn (?string $field): string => $this->toUtf8($field ?? ''),
                    $fields,
                );
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * @return list<list<string>>
     */
    private function readSpreadsheet(string $path): array
    {
        /*
         * Se identifica por CONTENIDO, no por extensión.
         *
         * `createReaderForFile()` mira el nombre del archivo, y el nombre
         * que llega acá es el del temporal que escribe PHP al recibir una
         * subida: `C:\Windows\Temp\php26F.tmp`, sin extensión. Con eso, la
         * importación funciona en los tests —donde el archivo conserva su
         * ruta original— y falla en producción, que es la peor combinación
         * posible.
         *
         * `identify()` prueba el `canRead()` de cada lector, que verifica
         * la firma real: OLE2 para el `.xls`, ZIP para el `.xlsx`.
         */
        $reader = IOFactory::createReader(IOFactory::identify($path, [
            IOFactory::READER_XLS,
            IOFactory::READER_XLSX,
            IOFactory::READER_ODS,
        ]));

        /*
         * Con `setReadDataOnly(true)` esto sería más liviano, pero una
         * celda de fecha volvería como `46239`: Excel guarda las fechas
         * como número y lo único que las distingue de un importe es el
         * formato, que ese modo descarta. Un extracto tiene cientos de
         * filas, no millones; la memoria no es el problema, confundir una
         * fecha con un importe sí.
         */
        // Una fila de más que el tope: si trae datos, el archivo lo excede.
        $reader->setReadFilter(new BoundedReadFilter($this->maxRows + 1, self::MAX_COLUMNS));

        $book = $reader->load($path);
        $sheet = $book->getActiveSheet();

        $lastRow = $sheet->getHighestRow();
        $lastColumn = $sheet->getHighestColumn();

        $rows = [];

        foreach ($sheet->getRowIterator(1, $lastRow) as $row) {
            $cells = $row->getCellIterator('A', $lastColumn);
            // Las columnas vacías importan: son la posición de las que no
            // lo están, y el extracto usa celdas combinadas.
            $cells->setIterateOnlyExistingCells(false);

            $values = [];

            foreach ($cells as $cell) {
                $values[] = $this->cellToString($cell);
            }

            $rows[] = $this->trimTrailingEmpty($values);
        }

        // Las hojas y las celdas se referencian entre sí: sin cortar ese
        // ciclo, un worker que importa varios extractos no libera ninguno.
        $book->disconnectWorksheets();

        if (count($rows) > $this->maxRows && $rows[$this->maxRows] !== []) {
            throw new RuntimeException(sprintf(
                'El archivo tiene más de %s filas con datos: no parece un extracto bancario.',
                number_format($this->maxRows, 0, ',', '.'),
            ));
        }

        return array_slice($rows, 0, $this->maxRows);
    }

    /**
     * Descarta las celdas vacías del final de la fila.
     *
     * Cargar los estilos trae de arrastre cada celda que Excel formateó
     * alguna vez: el extracto usa once columnas y la hoja devuelve 257.
     * Las vacías de la izquierda se conservan —son la posición de las que
     * no lo están—; las de la derecha no significan nada y, sin este
     * recorte, se guardarían tal cual en `raw_data`.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    private function trimTrailingEmpty(array $values): array
    {
        $last = -1;

        foreach ($values as $index => $value) {
            if ($value !== '') {
                $last = $index;
            }
        }

        return array_slice($values, 0, $last + 1);
    }

    private function cellToString(Cell $cell): string
    {
        $value = $cell->getValue();

        if ($value === null || $value === '') {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            if (ExcelDate::isDateTime($cell)) {
                $date = ExcelDate::excelToDateTimeObject($value);

                // Una fecha sin parte horaria sale como fecha a secas: el
                // `00:00:00` no es un dato del archivo, es relleno.
                return $date->format($date->format('His') === '000000' ? 'Y-m-d' : 'Y-m-d H:i:s');
            }

            return $this->toDecimalString((float) $value);
        }

        return $this->toUtf8(trim((string) $value));
    }

    /**
     * Decimal sin notación científica ni ceros de relleno: `473191.2`,
     * `-1253491.05`, `121`.
     */
    private function toDecimalString(float $value): string
    {
        $formatted = sprintf('%.'.self::NUMERIC_PRECISION.'F', $value);

        if (! str_contains($formatted, '.')) {
            return $formatted;
        }

        $trimmed = rtrim(rtrim($formatted, '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }

    /**
     * Los extractos de MacroOnline vienen en Windows-1252: sin esto, cada
     * `Comisión` y cada apellido con tilde entra roto a la base y ya no
     * hay forma de recuperarlo.
     */
    private function toUtf8(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }
}
