<?php

declare(strict_types=1);

namespace App\Modules\Shared\Actions;

use App\Modules\Shared\Models\DocumentSeries;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Toma el número siguiente de una serie.
 *
 * **Es la pieza delicada del sistema de comprobantes**, y el DER lo dice
 * sin rodeos: *«El correlativo se toma de `next_number` bajo lock de la
 * fila. Nunca se calcula con `MAX(number) + 1`, porque eso produce
 * duplicados bajo concurrencia»*.
 *
 * El escenario que hay que evitar es concreto: dos personas emitiendo un
 * recibo al mismo tiempo. Con `MAX + 1`, las dos leen el mismo máximo y
 * las dos escriben el mismo número. Y un recibo con número repetido no es
 * un error de sistema: es un comprobante que el área ya entregó firmado y
 * que ahora existe dos veces.
 *
 * `lockForUpdate()` hace que la segunda transacción espere a que la
 * primera termine. La espera es de milisegundos y sucede una vez por
 * comprobante.
 *
 * **Devolver el número no emite nada.** Quien lo pide tiene que usarlo
 * dentro de la misma transacción que crea el comprobante: si esa
 * transacción vuelve atrás, el incremento vuelve con ella y el número no
 * se pierde.
 */
final class TakeNextDocumentNumber
{
    /**
     * @return array{number: int, formatted: string, series_id: int}
     */
    public function handle(string $seriesCode): array
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException(
                'Pedir un número exige una transacción: el correlativo y el comprobante que lo '
                .'usa tienen que confirmarse juntos, o queda un número emitido sin documento.'
            );
        }

        // Configurar y emitir comparten este bloqueo antes de elegir la versión vigente.
        DB::select('SELECT pg_advisory_xact_lock(76104, 1)');

        /** @var DocumentSeries|null $series */
        $series = DocumentSeries::query()
            ->where('code', $seriesCode)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if ($series === null) {
            throw new RuntimeException("La serie de numeración «{$seriesCode}» no existe o está inactiva.");
        }

        if (! $series->is_active) {
            throw new RuntimeException("La serie «{$seriesCode}» está inactiva.");
        }

        $number = $series->next_number;
        if ($number > 999999999999) {
            throw ValidationException::withMessages(['numbering' => 'La numeración alcanzó su límite. Configurá una nueva serie.']);
        }

        $series->forceFill(['next_number' => $number + 1])->save();

        return [
            'series_id' => $series->id,
            'number' => $number,
            'formatted' => $series->formatNumber($number),
        ];
    }
}
