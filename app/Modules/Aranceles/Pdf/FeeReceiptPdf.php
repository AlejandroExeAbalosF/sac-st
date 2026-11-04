<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Pdf;

use App\Modules\Aranceles\Models\FeeChargeReceipt;
use App\Support\Money\AmountInWords;
use App\Support\Money\Decimal;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Contracts\View\View;

/**
 * El recibo de Aranceles, en papel en blanco (regla A91). Una sola plantilla para
 * la pantalla y la impresora, y lo que dice sale de la copia congelada.
 *
 * El diseño es provisorio: falta el visto bueno del área.
 */
final class FeeReceiptPdf
{
    public function render(FeeChargeReceipt $comprobante): PdfDocument
    {
        // A5 apaisado. Sin tope de renglones: lo que no entra sigue en otra hoja (A93).
        $pdf = Pdf::loadView('pdf.recibo-de-arancel', $this->datos($comprobante))->setPaper([0, 0, 595.28, 419.53]);

        // El total de hojas se conoce recién maquetado: va sobre el lienzo.
        $pdf->render();
        $lienzo = $pdf->getDomPDF()->getCanvas();

        if ($lienzo->get_page_count() > 1) {
            $fuente = $pdf->getDomPDF()->getFontMetrics()->getFont('DejaVu Sans');
            $texto = $comprobante->receipt->formatted_number.' · Hoja {PAGE_NUM} de {PAGE_COUNT}';
            $lienzo->page_text(26, $lienzo->get_height() - 22, $texto, $fuente, 7);
        }

        return $pdf;
    }

    public function preview(FeeChargeReceipt $comprobante): View
    {
        return view('pdf.recibo-de-arancel', $this->datos($comprobante));
    }

    public function filename(FeeChargeReceipt $comprobante): string
    {
        return 'recibo-arancel-'.str_replace('/', '-', $comprobante->receipt->formatted_number).'.pdf';
    }

    /**
     * @return array<string, mixed>
     */
    private function datos(FeeChargeReceipt $comprobante): array
    {
        $comprobante->loadMissing('receipt');
        $recibo = $comprobante->receipt;

        return [
            'recibo' => $recibo,
            'papel' => $comprobante->snapshot,
            'casilla' => $recibo->medium_snapshot === 'cash' ? 'cash' : 'deposit',
            'importe' => Decimal::format($recibo->amount),
            'importeEnLetras' => AmountInWords::for($recibo->amount),
            'selloUrl' => is_file(public_path('sello-organismo.png')) ? public_path('sello-organismo.png') : null,
        ];
    }
}
