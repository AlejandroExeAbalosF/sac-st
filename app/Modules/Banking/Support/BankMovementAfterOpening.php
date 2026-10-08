<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Models\CashBookOpening;
use Illuminate\Validation\ValidationException;

/**
 * La fecha de registro no convierte un movimiento histórico en dinero nuevo.
 *
 * Lo que llega del banco se asienta el día en que se registra, así que la
 * guarda de «nada anterior a la apertura» ya no lo alcanza por la fecha del
 * asiento. Un crédito o débito del extracto con fecha anterior a la
 * apertura de su moneda ya está dentro del saldo declarado en «depósitos
 * directos»: registrarlo lo contaría dos veces. La base lo impide igual
 * (`bank_allocation_after_opening`); esto lo dice con la salida, que es
 * dejarlo fuera del circuito con su motivo.
 */
final class BankMovementAfterOpening
{
    public function assertAllows(BankTransaction $transaction, int $cashBoxId, Currency $currency): void
    {
        $opening = CashBookOpening::for($cashBoxId, $currency);

        if ($opening !== null && $transaction->transaction_date !== null
            && $transaction->transaction_date->toDateString() < $opening->opened_on->toDateString()) {
            throw ValidationException::withMessages([
                'bankTransactionId' => sprintf(
                    'El movimiento bancario es anterior a la apertura de los libros en %s (%s): su importe ya está dentro '
                    .'del saldo declarado en la apertura y no se registra como un ingreso o egreso nuevo. '
                    .'En Banco › Movimientos, dejalo fuera del circuito con el motivo «incluido en la apertura».',
                    mb_strtolower($currency->label()),
                    $opening->opened_on->format('d/m/Y'),
                ),
            ]);
        }
    }
}
