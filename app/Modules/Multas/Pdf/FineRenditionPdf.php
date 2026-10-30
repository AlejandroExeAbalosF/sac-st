<?php

declare(strict_types=1);

namespace App\Modules\Multas\Pdf;

use App\Modules\Multas\Models\FineMonthlyReport;
use App\Support\Money\AmountInWords;
use App\Support\Money\Decimal;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Contracts\View\View;

/**
 * Las dos notas de la rendición mensual de Multas a la SAF, como las escribe
 * el área: los ingresos del mes en término y los fuera de término.
 *
 * Todo sale de lo congelado al emitir —filas, total, fecha y quién—, nunca
 * del estado actual: reimprimir dentro de un año da el mismo papel. El
 * membrete queda reservado, como en la nota de pase, hasta que el área
 * entregue el archivo del logotipo.
 */
final class FineRenditionPdf
{
    /** El mes en mayúsculas, como lo escribe el área: «SETIEMBRE». */
    private const MESES = [
        1 => 'ENERO', 2 => 'FEBRERO', 3 => 'MARZO', 4 => 'ABRIL', 5 => 'MAYO', 6 => 'JUNIO',
        7 => 'JULIO', 8 => 'AGOSTO', 9 => 'SETIEMBRE', 10 => 'OCTUBRE', 11 => 'NOVIEMBRE', 12 => 'DICIEMBRE',
    ];

    public function render(FineMonthlyReport $informe): PdfDocument
    {
        $pdf = Pdf::loadView('pdf.rendicion-multas', $this->datos($informe))->setPaper('a4');

        // «Hoja N de M» al pie, a la derecha: una nota de varias hojas tiene
        // que delatar la que falte al archivarla. El total de hojas recién se
        // conoce maquetado, así que va sobre el lienzo y no en la plantilla.
        $pdf->render();
        $lienzo = $pdf->getDomPDF()->getCanvas();
        $fuente = $pdf->getDomPDF()->getFontMetrics()->getFont('DejaVu Sans');
        $muestra = sprintf('Hoja %1$d de %1$d', $lienzo->get_page_count());
        $ancho = $pdf->getDomPDF()->getFontMetrics()->getTextWidth($muestra, $fuente, 7.5);
        $margen = 16 * 72 / 25.4;
        $lienzo->page_text($lienzo->get_width() - $margen - $ancho, $lienzo->get_height() - 32, 'Hoja {PAGE_NUM} de {PAGE_COUNT}', $fuente, 7.5, [0.33, 0.33, 0.33]);

        return $pdf;
    }

    public function preview(FineMonthlyReport $informe): View
    {
        return view('pdf.rendicion-multas', $this->datos($informe));
    }

    public function filename(FineMonthlyReport $informe): string
    {
        return sprintf(
            'rendicion-multas-%s-%s-v%d.pdf',
            $informe->closing->period_from->format('Y-m'),
            $informe->kind === FineMonthlyReport::ON_TIME ? 'en-termino' : 'fuera-de-termino',
            $informe->closing_version,
        );
    }

    /** @return array<string, mixed> */
    private function datos(FineMonthlyReport $informe): array
    {
        $mes = $informe->closing->period_from;
        $emitida = $informe->emitted_at;
        $periodo = self::MESES[$mes->month].' DE '.$mes->year;

        return [
            'informe' => $informe,
            'enTermino' => $informe->kind === FineMonthlyReport::ON_TIME,
            'periodo' => $periodo,
            'lugarYFecha' => sprintf(
                '%s, %02d de %s de %s.',
                config('multas.rendicion.lugar'),
                $emitida->day,
                mb_strtolower(self::MESES[$emitida->month]),
                number_format($emitida->year, 0, ',', '.'),
            ),
            'destinatario' => (array) config('multas.rendicion.destinatario'),
            // Lo guardado al emitir, nunca la configuración de hoy: reimprimir da el mismo papel.
            'cuenta' => count($informe->accounts) === 1 ? (string) $informe->accounts[0]['citation'] : null,
            'variasCuentas' => count($informe->accounts) > 1,
            'filas' => $informe->payload,
            'total' => Decimal::format($informe->total),
            'enLetras' => AmountInWords::for($informe->total),
            'membreteUrl' => is_file(public_path('membrete-organismo.png')) ? public_path('membrete-organismo.png') : null,
        ];
    }
}
