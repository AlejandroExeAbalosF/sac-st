<?php

declare(strict_types=1);

namespace App\Support\Navigation;

use Closure;

/**
 * El árbol de navegación del sistema, en un solo lugar.
 *
 * Cada pantalla registra su camino contra el nombre de su ruta, y los caminos
 * viven todos juntos en `routes/breadcrumbs.php`. Que sea un registro y no una
 * declaración por pantalla es deliberado:
 *
 * - La jerarquía se lee de corrido. Cuando entren Aranceles y Multas, el árbol
 *   completo sigue siendo un archivo.
 * - El camino se arma en el servidor, con los modelos ya vinculados por la
 *   ruta, así que puede nombrar el caso —«EXP-1234/2026»— y no una etiqueta
 *   genérica. Ese era el defecto de las migas estáticas del front.
 * - Una pantalla sin camino se detecta en CI, no en producción:
 *   `tests/Feature/Navigation/BreadcrumbsTest.php` recorre las rutas y falla.
 */
final class Breadcrumbs
{
    /**
     * Caminos declarados, por nombre de ruta.
     *
     * @var array<string, Closure>
     */
    private static array $trails = [];

    /**
     * Rutas que reutilizan el camino de otra.
     *
     * @var array<string, string>
     */
    private static array $aliases = [];

    /**
     * Declara el camino de una pantalla.
     *
     * El closure devuelve una lista de `Crumb` y recibe los parámetros de la
     * ruta ya resueltos a modelos, por nombre:
     * `fn (Expediente $expediente) => [...]`. La firma varía con cada ruta
     * —cero, uno o dos parámetros—, así que acá no se puede declarar una más
     * estrecha que `Closure`; quien la llama es el contenedor.
     */
    public static function for(string $routeName, Closure $trail): void
    {
        self::$trails[$routeName] = $trail;
    }

    /**
     * Hace que una ruta use el camino de otra.
     *
     * Existe por las rutas POST que vuelven a dibujar una pantalla —la
     * previsualización del extracto, sin ir más lejos—: la respuesta es la
     * misma pantalla, así que el camino tiene que ser el mismo.
     */
    public static function alias(string $routeName, string $target): void
    {
        self::$aliases[$routeName] = $target;
    }

    public static function has(?string $routeName): bool
    {
        if ($routeName === null) {
            return false;
        }

        return isset(self::$trails[self::$aliases[$routeName] ?? $routeName]);
    }

    /**
     * Resuelve el camino de una ruta.
     *
     * Devuelve un arreglo vacío cuando la ruta no tiene camino declarado: una
     * pantalla sin migas es un encabezado más pobre, nunca un error 500.
     *
     * @param  array<string, mixed>  $parameters
     * @return list<array{title: string, href: string|null}>
     */
    public static function resolve(?string $routeName, array $parameters = []): array
    {
        if ($routeName === null) {
            return [];
        }

        $trail = self::$trails[self::$aliases[$routeName] ?? $routeName] ?? null;

        if ($trail === null) {
            return [];
        }

        $crumbs = app()->call($trail, $parameters);

        if (! is_iterable($crumbs)) {
            return [];
        }

        $camino = [];

        foreach ($crumbs as $crumb) {
            $camino[] = self::toArray($crumb);
        }

        return $camino;
    }

    /**
     * @return array{title: string, href: string|null}
     */
    private static function toArray(Crumb $crumb): array
    {
        return $crumb->toArray();
    }

    /**
     * @return list<string>
     */
    public static function registered(): array
    {
        return array_keys(self::$trails);
    }

    public static function flush(): void
    {
        self::$trails = [];
        self::$aliases = [];
    }
}
