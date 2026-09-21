<?php

declare(strict_types=1);

namespace App\Support\Pdf;

/**
 * Una columna de la planilla.
 *
 * El ancho va en milímetros y es opcional: sin él la tabla reparte sola.
 * Conviene fijarlo en las columnas cortas —una fecha, un importe— para que
 * el sobrante se lo lleve la columna de nombres, que es la que se corta.
 *
 * **Nada de `<colgroup>`**: dompdf descarta los frames `table-column` sin
 * mirarles el ancho, así que la medida tiene que viajar en la celda del
 * encabezado. Es el mismo tropiezo que ya documenta la Orden de Pago.
 */
final class WorksheetColumn
{
    public function __construct(
        public readonly string $label,
        public readonly WorksheetAlign $align = WorksheetAlign::Left,
        public readonly ?string $width = null,
        /**
         * Si la celda se dibuja como un cuadrito vacío para tildar a mano.
         *
         * Es control interno de quien trabaja la cola, no un comprobante:
         * el papel que prueba algo es el recibo, y el pie de la planilla lo
         * dice. La fila igual trae su celda —vacía— para que las columnas
         * no se corran.
         */
        public readonly bool $tickBox = false,
    ) {}

    public static function amount(string $label, ?string $width = '24mm'): self
    {
        return new self($label, WorksheetAlign::Right, $width);
    }

    public static function tick(string $label, ?string $width = '18mm'): self
    {
        return new self($label, WorksheetAlign::Center, $width, tickBox: true);
    }
}
