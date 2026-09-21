<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Una imputación vigente de la cuota, para poder devolverla.
 *
 * Viaja el importe que **queda en pie**, no el original: una imputación ya
 * devuelta en parte no puede volver a devolverse entera.
 */
#[TypeScript]
final class InstallmentAllocationData extends Data
{
    public function __construct(
        public int $id,
        /** @var numeric-string */
        public string $amount,
        public string $receivedDate,
    ) {}
}
