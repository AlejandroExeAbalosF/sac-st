<?php

declare(strict_types=1);

namespace App\Modules\Shared\Permissions;

/**
 * Lo que cada módulo aporta al catálogo de permisos.
 *
 * Shared no puede nombrar los permisos de los otros módulos —la frontera se
 * verifica en CI—, así que cada uno declara los suyos y `AppServiceProvider`
 * los junta, igual que con el catálogo de auditoría. El orden importa: van
 * de abajo hacia arriba de la dependencia, porque un módulo puede usar los
 * grupos que registró otro más abajo.
 */
interface PermissionCatalogContributor
{
    public function register(PermissionCatalog $catalog): void;
}
