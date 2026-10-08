<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

use App\Modules\Ledger\Enums\Currency;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Lo que un módulo de arriba exige a la fecha de una apertura.
 *
 * La apertura es de Ledger, pero quién sabe si una fecha choca con algo
 * ya cargado es el módulo dueño de ese algo: hoy Haberes, con los papeles
 * del sistema anterior, que tienen que ser anteriores a la apertura. La
 * base lo impide igual (`cash_book_openings_after_legacy_papers`); esto
 * existe para decirlo al lado del campo en vez de con un error de
 * PostgreSQL.
 *
 * Ledger pregunta sin nombrar a nadie, y el módulo dueño lo implementa y
 * se registra en `AppServiceProvider`, como `CashTransferContents`.
 */
interface OpeningDateRule
{
    /** @throws ValidationException si la fecha no sirve para abrir ese libro */
    public function assertAllows(int $cashBoxId, Currency $currency, CarbonInterface $openedOn): void;
}
