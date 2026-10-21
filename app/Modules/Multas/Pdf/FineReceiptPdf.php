<?php

declare(strict_types=1);

namespace App\Modules\Multas\Pdf;

use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Models\FinePaymentReceipt;
use App\Support\Money\AmountInWords;
use App\Support\Money\Decimal;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Contracts\View\View;

/**
 * El recibo de Multas, sobre el formulario F.022 del área.
 *
 * Igual que el de Haberes: una sola plantilla para el papel en blanco y para
 * la hoja preimpresa del talonario, y la misma vista para la pantalla y la
 * impresora. Lo que dice de la multa sale del snapshot congelado al emitir,
 * nunca del estado actual (M09).
 *
 * La casilla del medio sale del pago: efectivo, cheque de tercero, orden de
 * pago judicial o depósito en la cuenta de Multas. El número de esa cuenta
 * también es de la copia: el que figuraba al emitir.
 */
final class FineReceiptPdf
{
    public function render(FinePaymentReceipt $comprobante, bool $conFondo = true): PdfDocument
    {
        return Pdf::loadView('pdf.recibo-de-multa', $this->datos($comprobante, $conFondo))
            // A5 apaisado, como el talonario.
            ->setPaper([0, 0, 595.28, 419.53]);
    }

    public function preview(FinePaymentReceipt $comprobante, bool $conFondo = true): View
    {
        return view('pdf.recibo-de-multa', $this->datos($comprobante, $conFondo));
    }

    public function filename(FinePaymentReceipt $comprobante): string
    {
        return 'recibo-multa-'.str_replace('/', '-', $comprobante->receipt->formatted_number).'.pdf';
    }

    /**
     * @return array<string, mixed>
     */
    private function datos(FinePaymentReceipt $comprobante, bool $conFondo): array
    {
        $comprobante->loadMissing(['receipt', 'payment']);
        $recibo = $comprobante->receipt;
        $pago = $comprobante->payment;

        return [
            'recibo' => $recibo,
            'snapshot' => $comprobante->snapshot,
            'esCancelatorio' => $comprobante->is_cancellation,
            'conFondo' => $conFondo,
            // Sobre el talonario el papel ya trae su número.
            'sobreTalonario' => ! $conFondo,
            'casilla' => match (true) {
                $pago->judicial_origin => 'judicial',
                $pago->medium === FinePaymentMedium::Cash => 'cash',
                $pago->medium === FinePaymentMedium::Cheque => 'cheque',
                default => 'deposit',
            },
            // La de la copia congelada, nunca la de la configuración actual.
            'cuenta' => $comprobante->snapshot['bankAccountNumber'] ?? null,
            'importe' => Decimal::format($recibo->amount),
            'importeEnLetras' => AmountInWords::for($recibo->amount),
            'totalMulta' => Decimal::format((string) ($comprobante->snapshot['obligation'] ?? '0')),
            'selloUrl' => is_file(public_path('sello-organismo.png')) ? public_path('sello-organismo.png') : null,
        ];
    }
}
