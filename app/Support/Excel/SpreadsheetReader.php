<?php

declare(strict_types=1);

namespace App\Support\Excel;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
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
        $sheet = $reader->load($path)->getActiveSheet();

        $rows = [];

        foreach ($sheet->getRowIterator() as $row) {
            $cells = $row->getCellIterator();
            // Las columnas vacías importan: son la posición de las que no
            // lo están, y el extracto usa celdas combinadas.
            $cells->setIterateOnlyExistingCells(false);

            $values = [];

            foreach ($cells as $cell) {
                $values[] = $this->cellToString($cell);
            }

            $rows[] = $this->trimTrailingEmpty($values);
        }

        return $rows;
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
