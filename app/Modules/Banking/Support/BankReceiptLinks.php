<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use Illuminate\Contracts\Auth\Access\Authorizable;

/** Cada módulo aporta los accesos a los cobros respaldados por una recepción. */
interface BankReceiptLinks
{
    /**
     * @param  list<int>  $receiptIds
     * @return array<int, list<array{label: string, href: string}>>
     */
    public function forReceipts(array $receiptIds, ?Authorizable $viewer): array;
}
