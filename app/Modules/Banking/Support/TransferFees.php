<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankTransaction;
use Illuminate\Database\Eloquent\Collection;

/**
 * La comisión que el banco cobró por una transferencia.
 *
 * Macro no descuenta la comisión de la transferencia: la debita en un
 * renglón aparte, con su propio causal y **la misma referencia** que la
 * transferencia a la que pertenece. En el extracto relevado:
 *
 * ```text
 *   04/08/2026  86934222  3913  Transf. MacrOnline E-set D/T   −891.841,00
 *   04/08/2026  86934222  3914  Comision Trf. MacrOL E-set         −121,00
 * ```
 *
 * Esa referencia compartida es lo que las une, así que una comisión se
 * reconoce por las cuatro cosas juntas: el par de causales —transferencia
 * E-set y su comisión—, misma cuenta, misma fecha y mismo número de
 * operación no vacío. Un número vacío no une nada: dos renglones sin
 * referencia no son de la misma operación. Y una comisión E-set no
 * acompaña a otro tipo de débito, aunque compartan referencia: un traslado
 * entre cuentas propias (3861, 3862) no la toma.
 *
 * Vive en Banking porque es un dato del banco, no del dominio: qué es una
 * comisión lo dice el causal, y no sabe para qué se pagó la transferencia.
 */
final class TransferFees
{
    /**
     * Qué causal de comisión acompaña a qué causal de transferencia.
     *
     * `3913` es «Transf. MacrOnline E-set D/T» y `3914`, «Comision Trf.
     * MacrOL E-set», la que el banco le cobra. Es el causal, y no el texto
     * del concepto, lo que se compara: el concepto se trunca y se reescribe,
     * el causal no. La base repite el par al confirmar un egreso
     * (`disbursements_transfer_debits_match`).
     */
    public const PAIRS = ['3913' => '3914'];

    /** Los causales con que el banco debita una comisión. */
    public const CAUSALS = ['3914'];

    /** Si el movimiento es la comisión de una transferencia. */
    public function isFee(BankTransaction $transaction): bool
    {
        return $transaction->direction === TransactionDirection::Debit
            && in_array(trim((string) $transaction->causal_code), self::CAUSALS, true);
    }

    /** El causal de la comisión que puede acompañar a este débito, si alguno. */
    public function feeCausalFor(BankTransaction $transfer): ?string
    {
        return self::PAIRS[trim((string) $transfer->causal_code)] ?? null;
    }

    /**
     * Las comisiones de la misma operación que este débito.
     *
     * Devuelve todas, sin filtrar por estado ni por disponibilidad: si hay
     * más de una, o si la única quedó fuera del circuito, decidirlo es de
     * quien la usa. Lo que no hace es elegir.
     *
     * @return Collection<int, BankTransaction>
     */
    public function siblingsOf(BankTransaction $transfer): Collection
    {
        $operacion = trim((string) $transfer->operation_id);
        $causal = $this->feeCausalFor($transfer);

        if ($causal === null || $operacion === '' || $transfer->transaction_date === null
            || $transfer->direction !== TransactionDirection::Debit) {
            return new Collection;
        }

        return BankTransaction::query()
            ->where('bank_account_id', $transfer->bank_account_id)
            ->whereDate('transaction_date', $transfer->transaction_date->toDateString())
            ->where('operation_id', $transfer->operation_id)
            ->where('direction', TransactionDirection::Debit->value)
            ->where('causal_code', $causal)
            ->whereKeyNot($transfer->id)
            ->orderBy('id')
            ->get();
    }
}
