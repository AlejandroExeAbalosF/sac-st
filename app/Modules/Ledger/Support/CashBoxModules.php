<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

use App\Modules\Shared\Models\CashBox;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * El registro de qué módulo opera cada caja, por código.
 *
 * Lo arma `AppServiceProvider`, que es el único lugar que puede nombrar a
 * todos. Una caja sin módulo no tiene circuito: sus pantallas no existen y
 * su cierre no se puede armar, en vez de caer en silencio en otra caja.
 */
final class CashBoxModules
{
    /** @var array<string, CashBoxModule> */
    private array $resueltos = [];

    /** @param  array<string, class-string<CashBoxModule>>  $modulos */
    public function __construct(
        private readonly Container $container,
        private readonly array $modulos,
    ) {}

    public function has(string $code): bool
    {
        return isset($this->modulos[$code]);
    }

    public function for(string $code): CashBoxModule
    {
        if (! $this->has($code)) {
            throw new LogicException("La caja «{$code}» no tiene un módulo que la opere.");
        }

        return $this->resueltos[$code] ??= $this->container->make($this->modulos[$code]);
    }

    public function forCashBox(int $cashBoxId): CashBoxModule
    {
        return $this->for((string) CashBox::query()->whereKey($cashBoxId)->value('code'));
    }
}
