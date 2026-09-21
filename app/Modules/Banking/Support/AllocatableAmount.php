<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\BankTransactionAllocation;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Cuánto de un movimiento bancario queda por imputar.
 *
 * Se calcula, no se guarda (§5.1). Un contador de «disponible» en
 * `bank_transactions` se desincronizaría en la primera reversión y nadie
 * lo notaría hasta que un movimiento apareciera respaldando más plata de
 * la que el banco informó.
 *
 * Vive acá y no dentro del Action porque lo necesitan los dos lados: el
 * Action para rechazar lo que no entra, y la pantalla para mostrar cuánto
 * hay antes de que el operador escriba un importe.
 */
final class AllocatableAmount
{
    /** @return numeric-string */
    public function for(BankTransaction $transaction): string
    {
        return Decimal::sub(
            Decimal::abs($transaction->amount),
            $this->allocated($transaction),
        );
    }

    /**
     * Lo ya imputado, neto de reversiones.
     *
     * @return numeric-string
     */
    public function allocated(BankTransaction $transaction): string
    {
        $neto = BankTransactionAllocation::query()
            ->where('bank_transaction_id', $transaction->id)
            ->selectRaw('COALESCE(SUM(CASE WHEN reversal_of_id IS NULL THEN amount ELSE -amount END), 0) AS neto')
            ->value('neto');

        return Decimal::scale((string) ($neto ?? '0'));
    }

    /**
     * Igual, pero bloqueando las imputaciones del movimiento.
     *
     * Es lo que impide que dos operadores registren al mismo tiempo dos
     * recepciones que por separado entran y juntas se pasan. Sin el
     * bloqueo, los dos leerían el mismo disponible y los dos creerían
     * tener lugar.
     *
     * Solo tiene sentido dentro de una transacción.
     *
     * @return numeric-string
     */
    public function forUpdate(BankTransaction $transaction): string
    {
        /*
         * El bloqueo va sobre el movimiento, no sobre sus imputaciones:
         * las filas que compiten todavía no existen y no hay nada que
         * bloquear en ellas. `FOR UPDATE` sobre el padre serializa a los
         * dos operadores en el único registro que ambos tienen a la vista.
         */
        DB::table('bank_transactions')
            ->where('id', $transaction->id)
            ->lockForUpdate()
            ->value('id');

        return $this->for($transaction);
    }
}
