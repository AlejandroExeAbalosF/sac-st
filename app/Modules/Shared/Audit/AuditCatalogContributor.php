<?php

declare(strict_types=1);

namespace App\Modules\Shared\Audit;

/**
 * Lo que cada módulo aporta al catálogo de auditoría.
 *
 * Shared no puede nombrar los códigos de los otros módulos —la frontera se
 * verifica en CI—, así que cada uno declara los suyos y `AppServiceProvider`
 * los junta. El orden importa: van de abajo hacia arriba de la
 * dependencia, porque un módulo puede usar las categorías que registró
 * otro más abajo.
 */
interface AuditCatalogContributor
{
    public function register(AuditCatalog $catalog): void;
}
