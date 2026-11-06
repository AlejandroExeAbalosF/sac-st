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
            // En el margen de abajo, fuera del marco.
            $lienzo->page_text(26, $lienzo->get_height() - 15, $texto, $fuente, 7);
        }

        return $pdf;
    }

    public function preview(FeeChargeReceipt $comprobante): View
    {
        return view('pdf.recibo-de-arancel', [...$this->datos($comprobante), 'enPantalla' => true]);
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
            'documento' => self::documento($comprobante->snapshot['payerDocument'] ?? null),
            'precios' => array_map(self::precio(...), $comprobante->snapshot['lines']),
            'casilla' => $recibo->medium_snapshot === 'cash' ? 'cash' : 'deposit',
            'importe' => Decimal::format($recibo->amount),
            'importeEnLetras' => AmountInWords::for($recibo->amount),
            'selloUrl' => is_file(public_path('sello-organismo.png')) ? public_path('sello-organismo.png') : null,
        ];
    }

    /**
     * Lo que el renglón cobró: cantidad por precio tiene que dar el importe del papel.
     *
     * Si alguien cambió el importe, el precio del catálogo ya no vale acá: se muestra el
     * que resulta, y el motivo queda en el sistema. Si no da justo al centavo —$ 100 en 3—
     * va vacío: un precio redondeado tampoco daría el importe.
     *
     * @param  array{quantity: int|null, unitPrice: string|null, rate: string|null, baseAmount: string|null, amount: string}  $linea
     */
    private static function precio(array $linea): string
    {
        $importe = (string) $linea['amount'];

        if ($linea['unitPrice'] !== null) {
            $cantidad = (string) ($linea['quantity'] ?? 1);
            $unitario = bcdiv($importe, $cantidad, 2);

            return Decimal::equals(bcmul($unitario, $cantidad, 2), $importe) ? '$ '.Decimal::format($unitario) : '';
        }

        if ($linea['rate'] === null || $linea['baseAmount'] === null) {
            return '';
        }

        return Decimal::equals(Decimal::percentOf((string) $linea['baseAmount'], (string) $linea['rate']), $importe)
            ? rtrim(rtrim(str_replace('.', ',', (string) $linea['rate']), '0'), ',').' % s/ $ '.Decimal::format((string) $linea['baseAmount'])
            : '$ '.Decimal::format($importe);
    }

    /**
     * El rótulo sale de los dígitos: once son un CUIT y menos, un DNI.
     *
     * @return array{label: string, number: string}|null
     */
    private static function documento(?string $numero): ?array
    {
        if ($numero === null || $numero === '') {
            return null;
        }

        return strlen($numero) === 11
            ? ['label' => 'CUIT', 'number' => substr($numero, 0, 2).'-'.substr($numero, 2, 8).'-'.substr($numero, 10)]
            : ['label' => 'DNI', 'number' => (string) preg_replace('/\B(?=(\d{3})+$)/', '.', $numero)];
    }
}
