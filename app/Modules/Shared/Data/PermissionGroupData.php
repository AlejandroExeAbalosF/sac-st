<?php

declare(strict_types=1);

namespace App\Modules\Shared\Data;

use Illuminate\Support\Str;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Los permisos agrupados por el módulo al que pertenecen.
 *
 * El agrupador es el prefijo del nombre —`caja.`, `recibos.`, `expedientes.`—
 * y no un catálogo de etiquetas escrito a mano: sesenta permisos con su
 * traducción al castellano serían sesenta oportunidades de que la pantalla
 * diga una cosa y el `can:` de la ruta haga otra. El nombre técnico es el
 * mismo que se lee en `routes/modules/*.php`, y esa correspondencia literal
 * es lo que hace revisable la matriz.
 */
#[TypeScript]
final class PermissionGroupData extends Data
{
    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $permissions,
    ) {}

    /**
     * @param  list<string>  $permissionNames
     * @return list<self>
     */
    public static function group(array $permissionNames): array
    {
        $grouped = [];

        foreach ($permissionNames as $permission) {
            $key = Str::before($permission, '.');
            $grouped[$key][] = $permission;
        }

        ksort($grouped);

        return array_map(
            fn (string $key): self => new self(
                key: $key,
                label: Str::of($key)->replace(['-', '_'], ' ')->ucfirst()->value(),
                permissions: $grouped[$key],
            ),
            array_keys($grouped),
        );
    }
}
