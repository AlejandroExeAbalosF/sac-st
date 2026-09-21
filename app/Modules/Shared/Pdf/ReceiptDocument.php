<?php

declare(strict_types=1);

namespace App\Modules\Shared\Pdf;

use App\Modules\Shared\Models\Receipt;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Contracts\View\View;

/**
 * Un comprobante que se puede imprimir.
 *
 * Existe desde que hay dos formularios distintos —ingreso y egreso— y una
 * sola pantalla que los muestra: quien reimprime un recibo no debería
 * tener que saber de cuál de los dos se trata para elegir la plantilla.
 * Eso lo resuelve `ReceiptPdfFactory` leyendo el tipo del propio
 * comprobante.
 *
 * Los tres métodos son los tres destinos del mismo papel: el PDF que se
 * manda a la impresora, el HTML que el visor muestra enmarcado, y el
 * nombre con el que se guarda.
 */
interface ReceiptDocument
{
    /**
     * @param  bool  $conFondo  `false` para imprimir sobre el talonario.
     * @param  bool|null  $encabezaTalonario  `null` respeta lo elegido al emitir.
     */
    public function render(
        Receipt $receipt,
        bool $conFondo = true,
        ?ReceiptPrintData $extra = null,
        ?bool $encabezaTalonario = null,
    ): PdfDocument;

    public function preview(
        Receipt $receipt,
        bool $conFondo = true,
        ?ReceiptPrintData $extra = null,
        ?bool $encabezaTalonario = null,
    ): View;

    public function filename(Receipt $receipt): string;
}
