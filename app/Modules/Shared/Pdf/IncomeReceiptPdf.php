<?php

declare(strict_types=1);

namespace App\Modules\Shared\Pdf;

use App\Modules\Shared\Models\Receipt;
use App\Support\Money\AmountInWords;
use App\Support\Money\Decimal;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Contracts\View\View;

/**
 * El recibo de ingreso, impreso sobre el formulario F.010.
 *
 * **Dos destinos, una plantilla.** Con fondo se dibuja el formulario
 * entero para papel en blanco; sin fondo salen solo los valores, en la
 * posición en que la hoja preimpresa ya trae sus rótulos. Separarlas en
 * dos vistas era la otra opción y es peor: se desalinean en cuanto una
 * cambia y la otra no.
 *
 * Vive en `Shared` junto a `receipts` y no en `Support`: ahí va la
 * infraestructura **sin dominio** —`Money`, `Excel`—, y esto conoce el
 * modelo del comprobante. Lo verifica un arch test.
 */
final class IncomeReceiptPdf implements ReceiptDocument
{
    /**
     * @param  bool  $conFondo  `false` para imprimir sobre el talonario.
     * @param  bool|null  $encabezaTalonario  `null` respeta lo elegido al
     *                                        emitir; un booleano lo pisa
     *                                        para esta impresion.
     */
    public function render(
        Receipt $receipt,
        bool $conFondo = true,
        ?ReceiptPrintData $extra = null,
        ?bool $encabezaTalonario = null,
    ): PdfDocument {
        return Pdf::loadView('pdf.recibo-de-ingreso', $this->datos($receipt, $conFondo, $extra, $encabezaTalonario))
            // El formulario real es A5 apaisado.
            ->setPaper([0, 0, 595.28, 419.53]);
    }

    /** La misma vista, sin PDF: es lo que la pantalla muestra como anticipo. */
    public function preview(
        Receipt $receipt,
        bool $conFondo = true,
        ?ReceiptPrintData $extra = null,
        ?bool $encabezaTalonario = null,
    ): View {
        return view('pdf.recibo-de-ingreso', $this->datos($receipt, $conFondo, $extra, $encabezaTalonario));
    }

    public function filename(Receipt $receipt): string
    {
        // Con barra el nombre de archivo se rompe en Windows.
        return 'recibo-'.str_replace('/', '-', $receipt->formatted_number).'.pdf';
    }

    /**
     * @return array<string, mixed>
     */
    private function datos(
        Receipt $receipt,
        bool $conFondo,
        ?ReceiptPrintData $extra,
        ?bool $encabezaTalonario = null,
    ): array {
        $receipt->loadMissing('person');

        return [
            'recibo' => $receipt,
            'conFondo' => $conFondo,
            /*
             * Cual de los dos numeros va arriba. Lo elegido al emitir es
             * el valor por defecto —de ahi que se guarde— y cada
             * impresion puede pedir el otro: los dos llevan al mismo
             * registro, porque la relacion esta guardada.
             */
            'encabezaTalonario' => ($encabezaTalonario ?? $receipt->prints_talonario_number)
                && $receipt->talonario_number !== null,
            /*
             * Sin fondo es porque se imprime sobre la hoja del talonario,
             * y ahí el número del sistema no va: el papel ya trae el suyo.
             */
            'sobreTalonario' => ! $conFondo,
            'importe' => Decimal::format($receipt->amount),
            'importeEnLetras' => AmountInWords::for($receipt->amount),
            'extra' => $extra ?? new ReceiptPrintData,
            /*
             * El sello y el logotipo del organismo. Todavía no están
             * cargados, y hasta que lo estén la plantilla reserva el lugar
             * en vez de dibujar una aproximación: es la identidad
             * institucional, y una imitación en un documento que se
             * entrega es peor que un espacio vacío.
             */
            'selloUrl' => $this->sello(),
        ];
    }

    private function sello(): ?string
    {
        $ruta = public_path('sello-organismo.png');

        return is_file($ruta) ? $ruta : null;
    }
}
