<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * De dónde se puede apartar plata del sistema anterior para una cuota.
 *
 * Viaja una vez por página: es el mismo para todas las cuotas del haber,
 * porque sale de la caja de Haberes en la moneda del haber.
 */
#[TypeScript]
final class LegacyFundsOptionsData extends Data
{
    public function __construct(
        /**
         * Lo que queda del sistema anterior sin asignar ni pagar.
         *
         * @var numeric-string
         */
        public string $pending,
        /**
         * Lo que queda del sistema anterior en efectivo: el tope de lo que
         * se aparta del cajón, aunque el cajón tenga más por cobros
         * posteriores.
         *
         * @var numeric-string
         */
        public string $cash,
        /** @var list<LegacyChequeOptionData> */
        public array $cheques,
        /**
         * Lo que la apertura declaró en cheques sin detallarlos: de ahí se
         * puede identificar un cheque que no está en la lista.
         *
         * @var numeric-string
         */
        public string $undetailedCheques,
        /**
         * Las cuentas, cada una con lo que le queda del sistema anterior.
         *
         * @var list<array{id: int, label: string, available: numeric-string}>
         */
        public array $bankAccounts,
    ) {}
}
