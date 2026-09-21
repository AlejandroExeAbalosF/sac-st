<?php

declare(strict_types=1);

namespace App\Support\Pdf;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Contracts\View\View;

/**
 * El molde de las planillas de pendientes.
 *
 * Una sola plantilla para el PDF y para la pantalla, igual que la Orden de
 * Pago: es lo que garantiza que la vista previa no muestre algo distinto de
 * lo que sale por la impresora.
 *
 * Vive en `Support` y no en un módulo porque no conoce ninguna de las tres
 * planillas que lo usan. Sabe dibujar un listado con encabezado, columnas,
 * totales y pie; qué filas van adentro es asunto del dominio.
 */
final class Worksheet
{
    public function render(WorksheetData $planilla): PdfDocument
    {
        return Pdf::loadView('pdf.planilla', ['planilla' => $planilla])
            ->setPaper('a4', $planilla->landscape ? 'landscape' : 'portrait');
    }

    /** La misma vista, sin PDF: es lo que la pantalla muestra como anticipo. */
    public function preview(WorksheetData $planilla): View
    {
        return view('pdf.planilla', ['planilla' => $planilla]);
    }
}
