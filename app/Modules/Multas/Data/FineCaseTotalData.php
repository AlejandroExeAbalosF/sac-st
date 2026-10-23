<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use App\Modules\Ledger\Enums\Currency;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Lo que suman las multas de un expediente en una moneda.
 *
 * Va una por moneda y no un total único: la base admite multas en pesos y en
 * dólares, y sumarlas en una sola cifra sería mostrar un importe que no
 * existe.
 */
#[TypeScript]
final class FineCaseTotalData extends Data
{
    public function __construct(
        public Currency $currency,
        /** @var numeric-string */
        public string $obligation,
        /** @var numeric-string */
        public string $debt,
    ) {}
}
