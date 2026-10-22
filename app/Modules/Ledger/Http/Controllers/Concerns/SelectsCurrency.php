<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Controllers\Concerns;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Http\Middleware\ResolveCashBox;
use App\Modules\Ledger\Support\CashBoxModules;
use App\Modules\Shared\Models\CashBox;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
 * quién la abra, que es lo que no se quiere en una pantalla
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
        $moneda = is_string($pedida) ? (Currency::tryFrom(mb_strtoupper($pedida)) ?? Currency::Ars) : Currency::Ars;

        /*
         * Y solo un libro que la caja lleve: Multas no tiene libro en
         * dólares, así que `?moneda=usd` muestra los pesos en vez de un libro
         * sin apertura y sin selector para volver.
         */
        $permitidas = $this->currenciesOf($request);

        return in_array($moneda, $permitidas, true) ? $moneda : $permitidas[0];
    }

    /**
     * Los libros de la caja de la ruta; los dos si la pantalla no tiene caja
     * (la apertura y el saldo anterior, que son de Haberes).
     *
     * @return non-empty-list<Currency>
     */
    protected function currenciesOf(Request $request): array
    {
        $caja = $request->attributes->get(ResolveCashBox::ATTRIBUTE);

        return $caja instanceof CashBox
            ? app(CashBoxModules::class)->for($caja->code)->currencies()
            : [Currency::Ars, Currency::Usd];
    }

    /**
     * Rechaza operar en un libro que la caja no lleva. Lo que llega del
     * formulario no se corrige en silencio: se dice al lado del campo.
     *
     * @throws ValidationException
     */
    protected function assertCurrencyAllowed(Request $request, Currency $currency): void
    {
        if (! in_array($currency, $this->currenciesOf($request), true)) {
            throw ValidationException::withMessages([
                'currency' => 'Esta caja no lleva libro en '.mb_strtolower($currency->label()).'.',
            ]);
        }
    }
}
