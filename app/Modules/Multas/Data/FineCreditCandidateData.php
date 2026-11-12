<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un crédito del extracto propuesto para verificar un pago informado, con
 * las razones en palabras.
 *
 * Las señales van en palabras y no como un puntaje: si el orden está
 * equivocado, quien verifica tiene que poder darse cuenta.
 */
#[TypeScript]
final class FineCreditCandidateData extends Data
{
    public function __construct(
        public FineBankCreditData $credit,
        /** @var array<string, string> */
        public array $signals,
        /** El CUIT del concepto es el de la persona de la multa: la confirmación más fuerte. */
        public bool $payerMatches,
    ) {}
}
