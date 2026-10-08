<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Ledger\Enums\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * El día en que el sistema empezó a contar, en una moneda.
 *
 * Es la apertura de la caja de Haberes en la moneda del haber: pesos y
 * dólares son cajas separadas y cada una se abre cuando le toca. Un papel
 * del sistema anterior tiene que ser de antes: si fuera del mismo día o
 * posterior, ese pago ya debería estar en el sistema.
 *
 * Pregunta a la misma función que usa la base
 * (`haberes_opening_date(moneda)`), así que el mensaje del Action y el
 * rechazo del trigger no pueden discrepar.
 */
final class LegacyCutoff
{
    public function date(Currency $currency): ?CarbonImmutable
    {
        $fecha = DB::scalar('SELECT haberes_opening_date(?)', [$currency->value]);

        return is_string($fecha) ? CarbonImmutable::parse($fecha) : null;
    }
}
