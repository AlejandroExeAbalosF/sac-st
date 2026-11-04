<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Permissions;

use App\Modules\Shared\Permissions\PermissionCatalog;
use App\Modules\Shared\Permissions\PermissionCatalogContributor;
use App\Modules\Shared\Permissions\PermissionDefinition;

/**
 * Lo que declara Aranceles (DER físico §9). Las asignaciones de fábrica son un
 * punto de partida: el área las cambia desde Roles.
 */
final class ArancelesPermissionCatalog implements PermissionCatalogContributor
{
    public function register(PermissionCatalog $catalog): void
    {
        $catalog->registerGroup('aranceles', 'Aranceles', 86);

        $catalog->register(
            new PermissionDefinition(
                'aranceles.ver',
                'Ver aranceles',
                'Consultar el catálogo de aranceles y sus precios.',
                'aranceles',
                ['administrador', 'contador', 'administrativo', 'consulta'],
            ),
            // Cambia lo que se propone cobrar a partir de una fecha.
            new PermissionDefinition(
                'aranceles.catalogo',
                'Administrar el catálogo de aranceles',
                'Dar de alta, corregir y desactivar tipos de arancel, y agregar o anular sus precios.',
                'aranceles',
                ['administrador'],
                sensitive: true,
            ),
        );
    }
}
