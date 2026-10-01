<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * El día en que el sistema empezó a contar.
 *
 * Es la primera apertura vigente de la caja de Haberes, en cualquier
 * moneda. Un papel del sistema anterior tiene que ser de antes: si fuera
 * del mismo día o posterior, ese pago ya debería estar en el sistema.
 *
 * Pregunta a la misma función que usa la base
 * (`haberes_opening_date()`), así que el mensaje del Action y el rechazo
 * del trigger no pueden discrepar.
 */
final class LegacyCutoff
{
    public function date(): ?CarbonImmutable
    {
        $fecha = DB::scalar('SELECT haberes_opening_date()');

        return is_string($fecha) ? CarbonImmutable::parse($fecha) : null;
    }
}
