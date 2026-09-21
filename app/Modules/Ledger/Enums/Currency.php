<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

/**
 * Las monedas que el organismo maneja.
 *
 * Son dos y **no se convierten nunca**. El área confirmó que se recibe y
 * se paga en la misma moneda, así que no hay cotización, ni evento de
 * conversión, ni cuenta de diferencia de cambio: las dos conviven
 * segregadas. Eso convierte lo que parecía un problema de cambio de
 * divisas en uno de separación, que se resuelve con una columna y tres
 * invariantes en la base.
 *
 * > Es una política nueva, no una ley de la naturaleza (corrección 23).
 * > Si mañana se admite recibir en una moneda y pagar en otra, este enum
 * > sigue sirviendo; lo que cambia es el alcance.
 */
enum Currency: string
{
    case Ars = 'ARS';
    case Usd = 'USD';

    public function label(): string
    {
        return match ($this) {
            self::Ars => 'Pesos',
            self::Usd => 'Dólares',
        };
    }

    /**
     * El símbolo que va delante del importe.
     *
     * `US$` y no `$` para el dólar: en una planilla donde conviven las dos
     * monedas, un `$` a secas es exactamente la ambigüedad que la columna
     * `currency` existe para eliminar.
     */
    public function symbol(): string
    {
        return match ($this) {
            self::Ars => '$',
            self::Usd => 'US$',
        };
    }
}
