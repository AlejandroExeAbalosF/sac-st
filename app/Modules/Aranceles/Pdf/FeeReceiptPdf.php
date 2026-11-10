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
            'bases' => array_map(self::base(...), $comprobante->snapshot['lines']),
            'casilla' => $recibo->medium_snapshot === 'cash' ? 'cash' : 'deposit',
            'importe' => Decimal::format($recibo->amount),
            'importeEnLetras' => AmountInWords::for($recibo->amount),
            'selloUrl' => is_file(public_path('sello-organismo.png')) ? public_path('sello-organismo.png') : null,
        ];
    }

    /**
     * El precio de la tarifa con que se calculó el renglón, aunque se haya cobrado otro importe.
     *
     * Al lado va el importe recibido: si alguien lo cambió —un redondeo, una excepción—,
     * la diferencia queda a la vista en el papel. El motivo sigue solo en el sistema.
     *
     * Un porcentual no tiene precio unitario: muestra lo que da la tasa, con la tasa
     * entre paréntesis, para compararlo directo con lo recibido. El monto sobre el que
     * se aplica va en la descripción, donde hay lugar (ver `base()`).
     *
     * @param  array{quantity: int|null, unitPrice: string|null, rate: string|null, baseAmount: string|null, amount: string}  $linea
     */
    private static function precio(array $linea): string
    {
        if ($linea['unitPrice'] !== null) {
            return '$ '.Decimal::format((string) $linea['unitPrice']);
        }

        if ($linea['rate'] === null || $linea['baseAmount'] === null) {
            return '';
        }

        $tasa = rtrim(rtrim(str_replace('.', ',', (string) $linea['rate']), '0'), ',');

        return '$ '.Decimal::format(Decimal::percentOf((string) $linea['baseAmount'], (string) $linea['rate'])).' ('.$tasa.' %)';
    }

    /**
     * El monto sobre el que se aplicó la tasa, para el final de la descripción.
     *
     * Se agrega al dibujar y no se guarda en la redacción: reemplazar el recibo puede
     * reescribir la descripción, y el monto no tiene que perderse ni quedar viejo.
     *
     * @param  array{rate: string|null, baseAmount: string|null}  $linea
     */
    private static function base(array $linea): string
    {
        return $linea['rate'] === null || $linea['baseAmount'] === null
            ? ''
            : ', s/ $ '.Decimal::format((string) $linea['baseAmount']);
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
