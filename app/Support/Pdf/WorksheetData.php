<?php

declare(strict_types=1);

namespace App\Support\Pdf;

use Carbon\CarbonInterface;

/**
 * Una planilla de pendientes, lista para dibujar.
 *
 * **Las celdas llegan formateadas.** El molde no sabe de dinero ni de
 * fechas: si formateara importes habría dos lugares donde se decide cómo
 * se ve un peso, y el día que uno cambie la planilla y la pantalla dirían
 * cosas distintas. Quien arma los datos usa `Decimal::format()`, que es lo
 * que ya usa el resto del sistema.
 *
 * **No es un comprobante.** No se numera, no toca `document_series` y
 * pedirla dos veces el mismo día da resultados distintos porque entre
 * medio se trabajó. Por eso `generatedAt` no es decorativo: es lo único
 * que le dice a quien la tiene en la mano a qué momento corresponde.
 */
final class WorksheetData
{
    /**
     * @param  list<WorksheetColumn>  $columns
     * @param  list<list<string>>  $rows  Una celda por columna, en el mismo
     *                                    orden. Las de tilde van vacías.
     * @param  array<string, string>  $totals  Rótulo => importe ya formateado.
     * @param  string  $slug  Para el nombre del archivo, sin fecha ni extensión.
     */
    public function __construct(
        public readonly string $title,
        public readonly string $slug,
        public readonly array $columns,
        public readonly array $rows,
        public readonly CarbonInterface $generatedAt,
        public readonly ?string $generatedBy = null,
        public readonly ?string $subtitle = null,
        /** Qué recorte se aplicó, en palabras. Una lista sin su filtro engaña. */
        public readonly ?string $filters = null,
        public readonly array $totals = [],
        public readonly ?string $footnote = null,
        /**
         * Apaisado. Una planilla de ocho columnas no entra en vertical, y
         * cortar un apellido para salvar la orientación no le sirve a nadie.
         */
        public readonly bool $landscape = true,
        /** Qué decir cuando no hay ni una fila. El vacío también es una respuesta. */
        public readonly string $emptyMessage = 'No hay nada pendiente.',
    ) {}

    public function filename(): string
    {
        return sprintf('%s-%s.pdf', $this->slug, $this->generatedAt->format('Y-m-d'));
    }
}
