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
    /** @param list<AllInstallmentRowData> $filas */
    public function build(array $filas, CarbonImmutable $generadaEl, ?string $generadaPor = null): WorksheetData
    {
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
            ],
            rows: array_map(fn (AllInstallmentRowData $fila): array => [
                $fila->expedienteNumber,
                $fila->beneficiaryName,
                $fila->installmentLabel,
                $fila->concept ?? '—',
                Decimal::format($fila->amount),
                $fila->status,
            ], $filas),
            generatedAt: $generadaEl,
            generatedBy: $generadaPor,
            subtitle: sprintf('%d cuotas registradas', count($filas)),
            filters: 'Incluye cuotas vigentes, pagadas, históricas y anuladas · Ordenadas por carga',
            footnote: 'Hoja de consulta. Los importes listados no representan dinero disponible en caja ni un total pendiente de pago.',
            emptyMessage: 'No hay cuotas registradas.',
        );
    }
}
