<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Pdf;

use App\Modules\Haberes\Data\AllInstallmentRowData;
use App\Support\Money\Decimal;
use App\Support\Pdf\WorksheetColumn;
use App\Support\Pdf\WorksheetData;
use Carbon\CarbonImmutable;

/** Inventario de cuotas, sin atribuir el total a caja ni a pagos pendientes. */
final class AllInstallmentsSheet
{
    /**
     * @param  list<AllInstallmentRowData>  $filas
     * @param  array<int, true>  $marcables  Cuotas presentes en las dos colas de trabajo
     */
    public function build(
        array $filas,
        array $marcables,
        CarbonImmutable $generadaEl,
        ?string $generadaPor = null,
        ?string $filtros = null,
    ): WorksheetData {
        return new WorksheetData(
            title: 'Todas las cuotas de Haberes',
            slug: 'planilla-todas-las-cuotas',
            columns: [
                new WorksheetColumn('Expediente', width: '31mm'),
                new WorksheetColumn('Beneficiario'),
                new WorksheetColumn('Haber / Cuota', width: '34mm'),
                new WorksheetColumn('Concepto'),
                WorksheetColumn::amount('Importe'),
                new WorksheetColumn('Estado', width: '40mm'),
                WorksheetColumn::tick('', width: '12mm'),
            ],
            rows: array_map(fn (AllInstallmentRowData $fila): array => $this->row(
                $fila,
                isset($marcables[$fila->installmentId]),
            ), $filas),
            generatedAt: $generadaEl,
            generatedBy: $generadaPor,
            subtitle: sprintf('%d cuotas registradas', count($filas)),
            filters: ($filtros ?? 'Sin filtros adicionales').' · Ordenadas por carga',
            footnote: 'Hoja de consulta. La marca es control manual y no confirma pagos ni reemplaza el recibo de egreso. Los importes no representan dinero disponible ni un total pendiente de pago.',
            emptyMessage: 'No hay cuotas registradas.',
        );
    }

    /** @return list<string|null> */
    public function row(AllInstallmentRowData $fila, bool $marcable): array
    {
        return [
            $fila->expedienteNumber,
            $fila->beneficiaryName,
            $fila->installmentLabel,
            $fila->concept ?? '—',
            Decimal::format($fila->amount),
            $fila->status.($fila->paymentMethod === null ? '' : ' · Salida: '.$fila->paymentMethod),
            $marcable ? '' : null,
        ];
    }
}
