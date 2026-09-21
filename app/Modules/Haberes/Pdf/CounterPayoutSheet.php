<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Pdf;

use App\Modules\Haberes\Data\CounterPayoutRowData;
use App\Support\Money\Decimal;
use App\Support\Pdf\WorksheetColumn;
use App\Support\Pdf\WorksheetData;
use Carbon\CarbonImmutable;

/**
 * La fila del mostrador, en papel.
 *
 * Con esta hoja el cajero sabe cuánto efectivo contar antes de abrir, y
 * puede ir tildando a medida que la gente retira sin perder el renglón.
 *
 * **La casilla no es una firma.** El papel que el beneficiario firma es el
 * recibo de egreso, y el pie de la planilla lo dice: si la marca de esta
 * hoja valiera como constancia, alguien terminaría dando por entregado un
 * dinero sin comprobante que lo respalde, que es exactamente lo que el
 * circuito existe para evitar.
 */
final class CounterPayoutSheet
{
    /**
     * @param  list<CounterPayoutRowData>  $filas
     * @param  numeric-string  $total  Ya sumado por quien también se lo pasa a
     *                                 la pantalla: el papel y la pantalla no
     *                                 pueden discrepar en cuánto efectivo hay
     *                                 que contar.
     */
    public function build(
        array $filas,
        string $total,
        CarbonImmutable $generadaEl,
        ?string $generadaPor = null,
    ): WorksheetData {
        $cajas = $this->cajas($filas);
        // La caja se repite en todas las filas mientras haya una sola, que
        // es el caso de hoy. Como columna gastaría ancho para decir siempre
        // lo mismo; arriba, ubica la hoja de un vistazo.
        $unaSolaCaja = count($cajas) === 1 ? $cajas[0] : null;
        $variasCajas = count($cajas) > 1;

        return new WorksheetData(
            title: 'Cuotas por entregar en efectivo',
            slug: 'planilla-mostrador',
            columns: array_values(array_filter([
                new WorksheetColumn('Expediente', width: '30mm'),
                new WorksheetColumn('Beneficiario'),
                new WorksheetColumn('Documento', width: '24mm'),
                new WorksheetColumn('Haber / Cuota', width: '34mm'),
                new WorksheetColumn('Concepto'),
                $variasCajas ? new WorksheetColumn('Caja', width: '30mm') : null,
                WorksheetColumn::amount('Importe'),
                new WorksheetColumn('Recibo ingreso', width: '28mm'),
                WorksheetColumn::tick('Retirado'),
            ])),
            rows: array_map(
                fn (CounterPayoutRowData $f): array => array_values(array_filter([
                    $f->expedienteNumber,
                    $f->beneficiaryName,
                    $f->beneficiaryDocument ?? '—',
                    $f->installmentLabel,
                    $f->concept ?? '—',
                    $variasCajas ? ($f->cashBoxName ?? '—') : null,
                    Decimal::format($f->amount),
                    $f->incomeReceiptNumber ?? '—',
                    '',
                ], fn (?string $celda): bool => $celda !== null)),
                $filas,
            ),
            generatedAt: $generadaEl,
            generatedBy: $generadaPor,
            subtitle: $unaSolaCaja === null
                ? 'Cuotas financiadas, con su recibo de ingreso emitido y sin trabas'
                : 'Cuotas financiadas, con su recibo de ingreso emitido y sin trabas · '.$unaSolaCaja,
            filters: 'Solo mostrador y solo efectivo · Ordenadas por apellido del beneficiario',
            totals: [
                /*
                 * El número por el que se imprime la hoja, así que va aunque
                 * no haya ni una fila: un total en cero es una respuesta.
                 */
                sprintf('Total a entregar (%d cuotas)', count($filas)) => Decimal::format($total),
            ],
            footnote: 'Hoja de trabajo. La marca de «Retirado» es control interno y no reemplaza '
                .'al recibo de egreso, que es el papel que el beneficiario firma.',
            emptyMessage: 'No hay cuotas en efectivo listas para entregar.',
        );
    }

    /**
     * Las cajas distintas que aparecen en la lista.
     *
     * @param  list<CounterPayoutRowData>  $filas
     * @return list<string>
     */
    private function cajas(array $filas): array
    {
        $nombres = [];

        foreach ($filas as $fila) {
            if ($fila->cashBoxName !== null) {
                $nombres[$fila->cashBoxName] = true;
            }
        }

        return array_keys($nombres);
    }
}
