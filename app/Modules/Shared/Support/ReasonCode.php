<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use BackedEnum;

/**
 * Un motivo de la lista de un módulo. Cada módulo tiene su enum; lo común
 * —cómo se valida, se guarda y se lee— vive en `Reason`.
 *
 * El caso `other` («Otro») es el que exige detalle.
 */
interface ReasonCode extends BackedEnum
{
    public function label(): string;
}
