<?php

declare(strict_types=1);

namespace App\Support\Excel;

use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Escribe una planilla `.xlsx` directo a la respuesta, fila por fila.
 *
 * Es infraestructura sin dominio, la contracara de `SpreadsheetReader`:
 * no sabe qué es un evento de auditoría. Escribe en streaming para que un
 * export grande no tenga que caber entero en memoria.
 *
 * **Los valores van como vienen.** Un importe llega ya escrito —«$ 1.250,00»—
 * y así se guarda: convertirlo a número de Excel sería meterle un `float`
 * a un dato que en todo el sistema es decimal exacto.
 */
final class SpreadsheetWriter
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<list<string|int|null>>  $rows
     */
    public static function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return new StreamedResponse(function () use ($headers, $rows): void {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $writer->addRow(self::row($headers, new Style(fontBold: true)));

            foreach ($rows as $row) {
                $writer->addRow(self::row($row));
            }

            $writer->close();
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            // Symfony arma la cabecera: escapa comillas y agrega el nombre
            // en UTF-8. Concatenado, un nombre con `"` o con tildes la rompía.
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $filename,
                Str::ascii($filename),
            ),
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Una cadena siempre es texto, aunque empiece con `=`.
     *
     * `Cell::fromValue()` convierte esas cadenas en fórmulas. Los motivos,
     * observaciones y nombres vienen de datos cargados por usuarios; si se
     * abrieran como fórmula, el archivo podría ejecutar contenido que la
     * auditoría solamente debía mostrar.
     *
     * @param  list<string|int|null>  $values
     */
    private static function row(array $values, ?Style $style = null): Row
    {
        return new Row(array_map(
            fn (string|int|null $value): Cell => is_string($value)
                ? new StringCell($value, $style)
                : Cell::fromValue($value, $style),
            $values,
        ));
    }
}
