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
 * Una cuenta compartida le interesa a más de uno, y cada uno ofrece lo suyo: quien
 * identifica decide de qué caja es. Si alguno lo puede identificar, las notas de
 * los otros —por qué no desde su caja— no aportan y no se muestran.
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
     * @return array<int, list<CreditAction>>
     */
    public function actionsFor(array $credits, ?Authorizable $viewer): array
    {
        $acciones = [];

        foreach ($this->handlers as $clase) {
            /** @var BankCreditHandler $handler */
            $handler = $this->container->make($clase);

            foreach ($handler->actionsFor($credits, $viewer) as $credito => $accion) {
                $acciones[$credito][] = $accion;
            }
        }

        return array_map(function (array $delCredito): array {
            $identificables = array_values(array_filter($delCredito, fn (array $a): bool => $a['href'] !== null));

            return $identificables === [] ? $delCredito : $identificables;
        }, $acciones);
    }
}
