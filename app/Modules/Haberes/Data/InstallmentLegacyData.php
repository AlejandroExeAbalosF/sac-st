<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Lo que una cuota tiene del sistema anterior.
 *
 * Viaja indexado por cuota, como el estado de la Orden y el del egreso:
 * la tarjeta lo usa para mostrar los papeles y para decidir si ofrece
 * registrar el pago.
 */
#[TypeScript]
final class InstallmentLegacyData extends Data
{
    public function __construct(
        /** El pago fuera del circuito, si está registrado. */
        public ?LegacySettlementData $settlement,
        /** @var list<LegacyDocumentData> */
        public array $documents,
        /**
         * Por qué no se la puede dar por pagada fuera del circuito, si no
         * se puede. `null` con la cuota pendiente quiere decir que sí.
         */
        public ?string $obstacle,
    ) {}
}
