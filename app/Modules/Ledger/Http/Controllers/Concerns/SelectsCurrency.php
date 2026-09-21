<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Controllers\Concerns;

use App\Modules\Ledger\Enums\Currency;
use Illuminate\Http\Request;

/**
 * La moneda que la pantalla está mirando.
 *
 * `cash_boxes` no lleva moneda: el mismo cajón guarda pesos y dólares, y
 * la base los separa en todo lo que importa —`journal_lines`,
 * `cash_counts` con su `UNIQUE (cash_box_id, counted_on, currency,
 * sequence)`, `period_closings`—. Son dos libros paralelos sobre la misma
 * caja, así que la moneda no es un dato de la caja sino de la vista: cuál
 * de los dos se está leyendo.
 *
 * Por eso va en la query string y no en la sesión. Un `/caja/dia?moneda=usd`
 * se comparte, se marca y se vuelve con el botón de atrás; una preferencia
 * guardada haría que la misma dirección muestre cosas distintas según
 * quién la abra, que es exactamente lo que no se quiere en una pantalla
 * donde el importe es el dato.
 *
 * Lo desconocido cae en pesos en vez de fallar: una URL mal escrita
 * muestra la caja de siempre, no un error.
 */
trait SelectsCurrency
{
    protected function selectedCurrency(Request $request): Currency
    {
        $pedida = $request->query('moneda');

        if (! is_string($pedida)) {
            return Currency::Ars;
        }

        return Currency::tryFrom(mb_strtoupper($pedida)) ?? Currency::Ars;
    }
}
