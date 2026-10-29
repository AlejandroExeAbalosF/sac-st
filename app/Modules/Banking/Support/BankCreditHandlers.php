<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Models\BankTransaction;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Container\Container;

/**
 * Los módulos que identifican créditos desde la lista de movimientos.
 *
 * Lo arma `AppServiceProvider`, que es el único lugar que puede nombrarlos.
 * Una cuenta compartida puede interesarle a más de uno: el primero que
 * responde por un crédito se queda con él.
 *
 * @phpstan-import-type CreditAction from BankCreditHandler
 */
final class BankCreditHandlers
{
    /** @param  list<class-string<BankCreditHandler>>  $handlers */
    public function __construct(
        private readonly Container $container,
        private readonly array $handlers,
    ) {}

    /**
     * @param  list<BankTransaction>  $credits
     * @return array<int, CreditAction>
     */
    public function actionsFor(array $credits, ?Authorizable $viewer): array
    {
        $acciones = [];

        foreach ($this->handlers as $clase) {
            /** @var BankCreditHandler $handler */
            $handler = $this->container->make($clase);
            $acciones += $handler->actionsFor($credits, $viewer);
        }

        return $acciones;
    }
}
