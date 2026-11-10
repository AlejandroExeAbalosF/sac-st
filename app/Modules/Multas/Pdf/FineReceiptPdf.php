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
            'aAbonar' => $conFondo ? self::aAbonar($comprobante->snapshot) : null,
            'selloUrl' => is_file(public_path('sello-organismo.png')) ? public_path('sello-organismo.png') : null,
        ];
    }

    /**
     * Lo que se debía antes de este pago, solo cuando entró de más.
     *
     * Con excedente el papel separa los dos importes: lo que había que abonar,
     * en lugar del «SON:», y debajo lo recibido en su recuadro, que es lo que
     * dicen las letras. En un pago parcial o justo no hace falta: el recuadro
     * y el total de la multa ya lo cuentan. Sale de la copia congelada.
     *
     * Solo en papel en blanco: la hoja del talonario trae el «SON:» impreso y
     * debajo no tiene rótulo, así que ahí se sigue la disposición de siempre.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private static function aAbonar(array $snapshot): ?string
    {
        if (Decimal::equals((string) ($snapshot['excess'] ?? '0'), '0') || ! isset($snapshot['obligation'], $snapshot['paidBefore'])) {
            return null;
        }

        return Decimal::format(Decimal::sub((string) $snapshot['obligation'], (string) $snapshot['paidBefore']));
    }
}
