<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Models\BankTransaction;
use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Lo que un módulo puede hacer con un crédito pendiente de la lista de
 * movimientos bancarios.
 *
 * Banking muestra los movimientos y no sabe qué es una multa ni una cuota:
 * identificar un crédito es de quien lleva la caja de esa cuenta. Cada
 * módulo responde por los créditos que le tocan, y se registra en
 * `AppServiceProvider`, igual que `CashBoxModule`.
 *
 * @phpstan-type CreditAction array{label: string, href: string|null, note: string|null}
 */
interface BankCreditHandler
{
    /**
     * Para cada crédito pendiente que le toca, una acción —«Identificar» con
     * su dirección— o una nota que diga por qué no se identifica desde acá.
     * Se pide **en lote**, por página. Un crédito que no está en el
     * resultado no es de este módulo.
     *
     * @param  list<BankTransaction>  $credits
     * @return array<int, CreditAction>
     */
    public function actionsFor(array $credits, ?Authorizable $viewer): array;
}
