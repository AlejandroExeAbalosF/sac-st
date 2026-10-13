<?php

declare(strict_types=1);

namespace App\Modules\Shared\Data;

use App\Modules\Shared\Permissions\PermissionCatalog;
use App\Modules\Shared\Permissions\PermissionDefinition;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Los permisos agrupados por concepto del área, en el orden del circuito.
 *
 * El rótulo y la explicación de cada permiso salen de `PermissionCatalog`,
 * el mismo lugar del que el seeder saca qué permisos existen: la pantalla
 * no puede describir algo que no se sembró ni dejar sin describir algo que
 * sí. El nombre técnico viaja igual, porque es el que se lee en
 * `routes/modules/*.php` y esa correspondencia literal es lo que hace
 * revisable la matriz.
 */
#[TypeScript]
final class PermissionGroupData extends Data
{
    /**
     * @param  list<PermissionData>  $permissions
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $permissions,
    ) {}

    /**
     * Los grupos que se pueden otorgar desde la pantalla.
     *
     * Solo entran los permisos que ya están en la tabla: si el catálogo
     * declara uno nuevo y todavía no se corrió el seeder, ofrecerlo como
     * casilla sería ofrecer algo que la validación va a rechazar.
     *
     * @param  list<string>  $seeded
     * @return list<self>
     */
    public static function fromCatalog(PermissionCatalog $catalog, array $seeded): array
    {
        $groups = [];

        foreach ($catalog->grouped() as $group) {
            $permissions = array_values(array_map(
                PermissionData::fromDefinition(...),
                array_filter(
                    $group['permissions'],
                    fn (PermissionDefinition $definition): bool => in_array($definition->name, $seeded, true),
                ),
            ));

            if ($permissions !== []) {
                $groups[] = new self($group['key'], $group['label'], $permissions);
            }
        }

        return $groups;
    }
}
