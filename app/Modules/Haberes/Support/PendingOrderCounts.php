<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Shared\Data\QueueSampleData;

/**
 * El resultado de la pasada de `PendingOrderQueues`.
 *
 * Las dos colas viajan juntas porque se resuelven juntas: separarlas en
 * dos llamadas duplicaría el recorrido de candidatos, que es lo caro.
 */
final readonly class PendingOrderCounts
{
    /**
     * @param  list<QueueSampleData>  $withoutOrderSamples
     * @param  list<QueueSampleData>  $withoutBankAccountSamples
     */
    public function __construct(
        /** Cuotas financiadas a las que ya se les puede emitir la Orden. */
        public int $withoutOrder,
        /** Cuotas con el dinero adentro que no pueden emitirla: falta el CBU. */
        public int $withoutBankAccount,
        public array $withoutOrderSamples,
        public array $withoutBankAccountSamples,
        /** Se alcanzó el tope de candidatos: los números son un piso. */
        public bool $hasMore,
    ) {}
}
