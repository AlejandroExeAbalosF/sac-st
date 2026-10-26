<?php

declare(strict_types=1);

namespace App\Support\Routing;

/**
 * La dirección de un registro que se reconoce por su número: el id y,
 * después de una barra, el número legible.
 *
 *     /haberes/expedientes/75/0030064-125957-2026-0
 *     /multas/expedientes/12/169607-2024-0
 *
 * **El id identifica; el número solo describe.** Va en su propio tramo
 * porque pegado al número era ambiguo —en Multas el sufijo también lleva
 * guion, y `169607-2024-75` no decía si 75 era el sufijo o el id—. Si el
 * número falta o quedó viejo porque se corrigió, la dirección igual resuelve
 * por el id, y `RedirectToCurrentRouteKey` la acomoda a la actual.
 *
 * La clave de la ruta es «id/número», así que `route()` arma la dirección
 * buena con solo recibir el modelo: Laravel no codifica la barra de un
 * parámetro. El patrón del parámetro la admite, y exige que el número
 * empiece con una cifra para que `agregar-multa` o `nuevo` no se tomen por
 * número (ver `ReadableRoute::PATTERN`).
 *
 * Quien lo usa define `routeNumber()`: el número con guiones en lugar de
 * barras, o `null` si el registro no lo tiene —entonces va solo con el id—.
 */
trait ReadableRouteKey
{
    abstract public function routeNumber(): ?string;

    /**
     * Cómo se llama la clave en los DTO y en los helpers del front.
     *
     * Wayfinder tipa el parámetro con este nombre: `show({ routeKey })` en
     * lugar de un id que después habría que redirigir. No es una columna; la
     * resolución la hace `resolveRouteBinding`.
     */
    public function getRouteKeyName(): string
    {
        return 'routeKey';
    }

    public function getRouteKey(): string
    {
        $numero = $this->routeNumber();

        // Un número que la ruta no aceptaría armaría una dirección muerta: va solo el id.
        if ($numero === null || preg_match('~^'.ReadableRoute::NUMBER.'$~', $numero) !== 1) {
            return (string) $this->getKey();
        }

        return $this->getKey().'/'.$numero;
    }

    /**
     * Resuelve por el id del principio. El número no se mira: si no
     * coincide con el actual, la redirección lo corrige.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?static
    {
        if (preg_match('~^(\d+)(?:/|$)~', (string) $value, $partes) !== 1) {
            return null;
        }

        return $this->newQuery()->find($partes[1]);
    }
}
