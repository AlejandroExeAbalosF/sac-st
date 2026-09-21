<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Pdf;

use App\Modules\Haberes\Models\DepositTicket;
use App\Support\Money\Decimal;
use App\Support\Pdf\WorksheetAlign;
use App\Support\Pdf\WorksheetColumn;
use App\Support\Pdf\WorksheetData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Los depósitos que trajo el expediente y el banco todavía no confirmó.
 *
 * Es la cola que hoy vive en `/depositos`, en papel. Sirve para barrerla
 * contra el extracto o para reclamarle al empleador: un comprobante que
 * lleva tres semanas sin aparecer significa que el depósito nunca se hizo
 * o que falta importar un período, y las dos cosas se resuelven con la
 * lista en la mano.
 *
 * **Mantiene el orden de la pantalla** —los más viejos arriba, que es lo
 * que ya hace `DepositTicket::scopeWaiting()`—. El papel y la pantalla
 * tienen que decir lo mismo en el mismo orden: si no, quien coteja los dos
 * pierde el renglón cada vez que levanta la vista.
 */
final class PendingDepositSheet
{
    /**
     * @param  Collection<int, DepositTicket>  $tickets  Con `expediente.employer`,
     *                                                   `account` e `installment`
     *                                                   precargados: son ocho
     *                                                   columnas por fila.
     */
    public function build(
        Collection $tickets,
        CarbonImmutable $generadaEl,
        ?string $generadaPor = null,
    ): WorksheetData {
        return new WorksheetData(
            title: 'Depósitos esperando acreditación',
            slug: 'planilla-depositos-esperando',
            columns: [
                new WorksheetColumn('Fecha', width: '20mm'),
                new WorksheetColumn('Expediente', width: '32mm'),
                new WorksheetColumn('Empleador'),
                new WorksheetColumn('Cuota', width: '20mm'),
                new WorksheetColumn('Cuenta', width: '34mm'),
                new WorksheetColumn('N.º operación', width: '30mm'),
                WorksheetColumn::amount('Importe'),
                new WorksheetColumn('Días', WorksheetAlign::Right, '14mm'),
                WorksheetColumn::tick('Acreditado'),
            ],
            rows: array_values($tickets->map(fn (DepositTicket $t): array => [
                $this->fecha($t),
                $t->expediente->display_number,
                $t->expediente->employer->name ?? '—',
                $t->installment === null ? '—' : 'Cuota '.$t->installment->installment_number,
                $t->account->label,
                $t->operation_number ?? '—',
                Decimal::format($t->amount),
                (string) (int) $t->deposited_at->diffInDays($generadaEl, false),
                '',
            ])->all()),
            generatedAt: $generadaEl,
            generatedBy: $generadaPor,
            subtitle: 'Comprobantes cargados cuyo crédito todavía no apareció en el extracto',
            filters: 'Estado: esperando en el banco · Ordenados del más antiguo al más reciente',
            totals: $this->totales($tickets),
            footnote: 'Hoja de trabajo. Lo que se tilde acá no acredita nada: el depósito queda '
                .'confirmado cuando el ticket se cruza con el movimiento del extracto.',
            emptyMessage: 'No hay depósitos esperando acreditación.',
        );
    }

    /**
     * La fecha con la hora del ticket cuando la trae.
     *
     * Dos depósitos del mismo empleador el mismo día solo se distinguen por
     * ahí, y es justamente el caso en que alguien va a querer distinguirlos.
     */
    private function fecha(DepositTicket $ticket): string
    {
        $fecha = $ticket->deposited_at->format('d/m/Y');

        return $ticket->deposited_time === null
            ? $fecha
            : $fecha.' '.mb_substr($ticket->deposited_time, 0, 5);
    }

    /**
     * Un total por moneda, que sale de la cuenta de destino.
     *
     * El ticket no declara moneda: declara a qué cuenta fue, y la moneda es
     * de la cuenta. Sumar importes de dos monedas en un solo número sería
     * escribir una cifra que no existe.
     *
     * @param  Collection<int, DepositTicket>  $tickets
     * @return array<string, string>
     */
    private function totales(Collection $tickets): array
    {
        $porMoneda = [];

        foreach ($tickets as $ticket) {
            $moneda = $ticket->account->currency;
            $porMoneda[$moneda] = Decimal::add($porMoneda[$moneda] ?? '0', $ticket->amount);
        }

        $totales = [];

        foreach ($porMoneda as $moneda => $importe) {
            $rotulo = count($porMoneda) === 1
                ? sprintf('Total (%d comprobantes)', $tickets->count())
                : sprintf('Total %s', $moneda);

            $totales[$rotulo] = Decimal::format($importe);
        }

        return $totales;
    }
}
