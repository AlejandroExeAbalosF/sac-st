<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Models\BankTransaction;
use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Referencias que cada módulo conoce sobre los movimientos de una página.
 * Banco no necesita conocer expedientes ni cuotas para mostrarlas.
 *
 * @phpstan-type MovementReference array{installmentId: int, expediente: string, beneficiary: string, haber: int, installment: int, status: string, amount: string|null, href: string}
 */
interface BankMovementReferences
{
    /**
     * @param  list<BankTransaction>  $transactions
     * @return array<int, list<MovementReference>>
     */
    public function forTransactions(array $transactions, ?Authorizable $viewer): array;
}
