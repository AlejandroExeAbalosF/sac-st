<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un cheque de la cartera de la apertura con saldo sin asignar.
 *
 * Trae lo que se anotó al abrir los libros —expediente y beneficiario del
 * papel— porque es como el operador lo reconoce en la cartera.
 */
#[TypeScript]
final class LegacyChequeOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $number,
        public ?string $bank,
        /** @var numeric-string */
        public string $amount,
        /** @var numeric-string */
        public string $available,
        public ?string $expediente,
        public ?string $beneficiary,
    ) {}
}
