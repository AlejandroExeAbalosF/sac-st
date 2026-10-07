<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * La cartera de cheques de la caja: los papeles uno por uno, y lo que la
 * apertura declaró como total sin detallarlos.
 *
 * `detailed + undetailed = total`, y el total es el saldo de «Cheques en
 * custodia» que muestra la caja.
 */
#[TypeScript]
final class ChequeInventoryData extends Data
{
    public function __construct(
        /** @var list<ChequeInCustodyData> */
        public array $cheques,
        /** @var numeric-string */
        public string $detailed,
        /** @var numeric-string */
        public string $undetailed,
        /** @var numeric-string */
        public string $total,
    ) {}
}
