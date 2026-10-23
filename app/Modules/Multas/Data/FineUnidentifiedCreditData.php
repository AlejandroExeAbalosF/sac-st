<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un crédito de la cuenta compartida que nadie identificó todavía (M08), con
 * los pagos informados del mismo importe que podrían ser el suyo.
 *
 * Los informados van primero porque identificar uno de ellos conserva su
 * presentación —que decide el término— y su respaldo. Crear un pago nuevo es
 * para el crédito que nadie informó.
 */
#[TypeScript]
final class FineUnidentifiedCreditData extends Data
{
    public function __construct(
        public FineBankCreditData $credit,
        /**
         * Entró antes de que la caja abriera: no se verifica como un pago
         * nuevo, se registra como pago anterior desde la ficha de la multa.
         */
        public bool $beforeOpening,
        /** @var list<FinePendingPaymentData> */
        public array $reported,
    ) {}
}
