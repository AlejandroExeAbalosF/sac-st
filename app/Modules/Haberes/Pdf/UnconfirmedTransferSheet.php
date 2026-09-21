<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Pdf;

use App\Modules\Haberes\Data\UnconfirmedTransferRowData;
use App\Support\Money\Decimal;
use App\Support\Pdf\WorksheetColumn;
use App\Support\Pdf\WorksheetData;
use Carbon\CarbonImmutable;

/**
 * Los pagos por transferencia que el sistema todavía no dio por hechos.
 *
 * Es la lista de lo que existe en el banco y no en los libros: el organismo
 * avisó que transfirió, o el débito apareció en el extracto, o están los
 * dos y falta que el contador los coteje contra la Orden (§2.3.4).
 *
 * Se imprime para cruzarla contra el extracto y para reclamarle al
 * organismo lo que informó y nunca se debitó. La columna «Estado» dice
 * cuál de las dos mitades falta, que es lo que decide a quién hay que ir a
 * buscar.
 */
final class UnconfirmedTransferSheet
{
    /**
     * @param  list<UnconfirmedTransferRowData>  $filas
     * @param  numeric-string  $total  Ya sumado por quien también alimenta la
     *                                 pantalla, para que las dos digan lo mismo.
     */
    public function build(
        array $filas,
        string $total,
        CarbonImmutable $generadaEl,
        ?string $generadaPor = null,
    ): WorksheetData {
        return new WorksheetData(
            title: 'Transferencias sin confirmar',
            slug: 'planilla-transferencias-sin-confirmar',
            columns: [
                new WorksheetColumn('Expediente', width: '30mm'),
                new WorksheetColumn('Beneficiario'),
                new WorksheetColumn('Haber / Cuota', width: '30mm'),
                WorksheetColumn::amount('Importe'),
                new WorksheetColumn('Orden N.º', width: '26mm'),
                new WorksheetColumn('Informe', width: '22mm'),
                new WorksheetColumn('Débito', width: '22mm'),
                new WorksheetColumn('Estado', width: '38mm'),
                WorksheetColumn::tick('Confirmado'),
            ],
            rows: array_map(
                fn (UnconfirmedTransferRowData $f): array => [
                    $f->expedienteNumber,
                    $f->beneficiaryName,
                    $f->installmentLabel,
                    Decimal::format($f->amount),
                    $f->paymentOrderNumber ?? '—',
                    $this->fecha($f->reportedAt),
                    $this->fecha($f->debitObservedAt),
                    $f->status->label(),
                    '',
                ],
                $filas,
            ),
            generatedAt: $generadaEl,
            generatedBy: $generadaPor,
            subtitle: 'El dinero salió y el egreso todavía no está confirmado en el sistema',
            filters: 'Informadas, con débito observado o listas para validar · '
                .'Ordenadas de la más antigua a la más reciente',
            totals: [
                sprintf('Total sin confirmar (%d egresos)', count($filas)) => Decimal::format($total),
            ],
            footnote: 'Hoja de trabajo. Un egreso queda confirmado cuando el contador coteja Orden, '
                .'informe y débito: tildarlo acá no postea ningún asiento.',
            emptyMessage: 'No hay transferencias esperando confirmación.',
        );
    }

    /**
     * Una fecha que puede no haber ocurrido todavía.
     *
     * El guion no es decoración: en esta planilla la mitad que falta es el
     * dato importante, y una celda vacía se lee como un olvido de carga.
     */
    private function fecha(?string $iso): string
    {
        return $iso === null ? '—' : CarbonImmutable::parse($iso)->format('d/m/Y');
    }
}
