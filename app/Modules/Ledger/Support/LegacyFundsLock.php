<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

use App\Modules\Ledger\Enums\Currency;
use Illuminate\Support\Facades\DB;

/**
 * El bloqueo que ordena los consumos de `LEGACY_FUNDS`.
 *
 * Es el mismo que toma la guarda de la base
 * (`legacy_funds_balance_check`, migración `keep_legacy_funds_non_negative`)
 * antes de sumar el saldo. Un Action que lo toma antes de leer el
 * pendiente lee lo mismo que la base va a decidir al confirmar, y da su
 * mensaje en vez del rechazo crudo del trigger.
 *
 * Dura lo que la transacción: se llama adentro de `DB::transaction`.
 */
final class LegacyFundsLock
{
    public function acquire(int $cashBoxId, Currency $currency): void
    {
        DB::select(
            'SELECT pg_advisory_xact_lock(hashtext(?), ?)',
            ['sacst.legacy_funds.'.$currency->value, $cashBoxId],
        );
    }
}
