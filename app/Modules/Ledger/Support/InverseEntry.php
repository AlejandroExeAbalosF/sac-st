<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Models\JournalLine;
use App\Support\Money\Decimal;

/**
 * Las líneas que deshacen un asiento: las mismas, del lado contrario.
 *
 * Copia **todas** las dimensiones de cada línea —moneda, caja, cuenta
 * bancaria, depositante y cuota—, no solo la cuenta y el importe. Un
 * inverso armado a mano tiende a olvidarse de alguna, y cada olvido es un
 * saldo que no vuelve a cero: la moneda cae en pesos aunque el original
 * fuera en dólares, o el banco vuelve sin la caja a la que pertenecía.
 *
 * Tampoco elige las cuentas: si el original sacó un cheque de custodia, el
 * inverso lo devuelve a custodia y no a la caja de efectivo.
 */
final class InverseEntry
{
    /** @return list<EntryLine> */
    public function of(int $financialEventId): array
    {
        return array_values(JournalLine::query()
            ->where('financial_event_id', $financialEventId)
            ->orderBy('id')
            ->get()
            ->map(function (JournalLine $linea): EntryLine {
                $esDebito = ! Decimal::equals(Decimal::scale($linea->debit), '0');

                $invertida = $esDebito
                    ? EntryLine::credit($linea->account_code, Decimal::scale($linea->debit))
                    : EntryLine::debit($linea->account_code, Decimal::scale($linea->credit));

                return $invertida
                    ->in(Currency::from($linea->currency))
                    ->onCashBox($linea->cash_box_id)
                    ->onBankAccount($linea->bank_account_id)
                    ->from($linea->depositor_id)
                    ->forInstallment($linea->haber_id, $linea->beneficiary_installment_id);
            })
            ->all());
    }
}
