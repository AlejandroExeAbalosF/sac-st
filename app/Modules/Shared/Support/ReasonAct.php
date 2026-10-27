<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use BackedEnum;

/**
 * Un acto que pide motivo, con la lista que ofrece. La base repite la misma
 * lista en el CHECK de la columna de cada acto.
 */
interface ReasonAct extends BackedEnum
{
    /**
     * @return list<ReasonCode>
     */
    public function codes(): array;
}
