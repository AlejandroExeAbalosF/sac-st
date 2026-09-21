<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Pdf;

use App\Modules\Haberes\Models\Pase;
use App\Support\Money\AmountInWords;
use App\Support\Money\Decimal;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Contracts\View\View;

/**
 * La nota de Pase, sobre papel con membrete del organismo.
 *
 * **Es una nota, no un formulario**: párrafos corridos con los datos
 * embebidos, y por eso su plantilla no se parece a la de la Orden. El
 * texto es fijo —el área lo confirmó— y lo que cambia son el destinatario,
 * la referencia del expediente, el beneficiario, el importe y la foja
 * donde el CBU está informado.
 *
 * **La firma va en blanco.** El área pidió expresamente que salga así,
 * para que la firme quien corresponda; el sistema no puede afirmar quién
 * lo hizo hasta que alguien se lo diga.
 *
 * **El membrete también.** El lugar está reservado y dibuja un marcador
 * punteado en vez de una aproximación: es identidad institucional, y una
 * imitación en un documento que va a otro organismo es peor que un
 * espacio vacío. Espera el archivo del área, igual que el sello del
 * recibo.
 */
final class PasePdf
{
    public function render(Pase $pase): PdfDocument
    {
        return Pdf::loadView('pdf.nota-de-pase', $this->datos($pase))->setPaper('a4');
    }

    /** La misma vista, sin PDF: es lo que la pantalla muestra como anticipo. */
    public function preview(Pase $pase): View
    {
        return view('pdf.nota-de-pase', $this->datos($pase));
    }

    /**
     * @param  bool  $borrador  Un papel sin emitir, que se marca en el nombre.
     */
    public function filename(Pase $pase, bool $borrador = false): string
    {
        $orden = $pase->paymentOrder;

        // Con barra el nombre de archivo se rompe en Windows.
        $numero = str_replace('/', '-', (string) $orden->formatted_number);

        return 'pase-'.$numero.($borrador ? '-borrador' : '').'.pdf';
    }

    /**
     * @return array<string, mixed>
     */
    private function datos(Pase $pase): array
    {
        $pase->loadMissing('paymentOrder');
        $orden = $pase->paymentOrder;

        return [
            'pase' => $pase,
            'orden' => $orden,
            'importe' => Decimal::format($orden->amount),
            'importeEnLetras' => AmountInWords::for($orden->amount),
            /*
             * «Salta, 10 de Junio de 2026». Con el mes en castellano y con
             * inicial mayúscula, como el papel del área.
             */
            'fechaLarga' => $this->fechaLarga($pase),
            'membreteUrl' => $this->membrete(),
        ];
    }

    private function fechaLarga(Pase $pase): string
    {
        $meses = [
            1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
            'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre',
        ];

        $fecha = $pase->issue_date;

        return sprintf(
            '%d de %s de %d',
            (int) $fecha->format('j'),
            $meses[(int) $fecha->format('n')],
            (int) $fecha->format('Y'),
        );
    }

    private function membrete(): ?string
    {
        $ruta = public_path('membrete-organismo.png');

        return is_file($ruta) ? $ruta : null;
    }
}
