<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * La cartera de cheques de la caja de Multas, uno por uno, y lo que la
 * apertura declaró como total sin detallar.
 *
 * `detailed + undetailed = total`, y el total es el saldo de «Cheques en
 * custodia» que muestra la caja.
 */
#[TypeScript]
final class FineChequeInventoryData extends Data
{
    public function __construct(
        /** @var list<FineChequeInCustodyData> */
        public array $cheques,
        /** @var numeric-string */
        public string $detailed,
        /** @var numeric-string */
        public string $undetailed,
        /** @var numeric-string */
        public string $total,
        /** Si quien mira puede abrir la ficha de cada multa (`multas.ver`). */
        public bool $canOpenFines,
    ) {}
}
