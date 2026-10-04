<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Los cheques que una cuota tiene solo en parte.
 *
 * Un cheque es un papel y se entrega como llegó: entero. Si lo que la
 * cuota tiene de un cheque no es el cheque completo —se liberó el
 * excedente de una cuota que bajó, o lo comparte con otra—, entregarlo le
 * daría al beneficiario más de lo suyo y dejaría el resto en
 * `CHEQUES_IN_CUSTODY` sin ningún cheque que lo respalde. El depósito no
 * tiene ese problema: lleva el papel completo (`InstallmentDeposit`).
 *
 * Se suma por cheque, no por asignación: dos asignaciones del mismo cheque
 * a la misma cuota que juntas lo completan son un cheque entero.
 */
final class PartialCheques
{
    /**
     * El primero que la cuota tiene en parte, o `null` si todos están
     * enteros.
     *
     * @return array{number: string|null, amount: numeric-string, held: numeric-string}|null
     */
    public function first(BeneficiaryInstallment $installment): ?array
    {
        $enPie = 'funding_allocations.amount - COALESCE(('
            .'SELECT SUM(reversions.amount) FROM funding_allocations AS reversions '
            .'WHERE reversions.reversal_of_id = funding_allocations.id'
            .'), 0)';

        $fila = DB::table('funding_allocations')
            ->join('fund_receipts', 'fund_receipts.id', '=', 'funding_allocations.fund_receipt_id')
            ->where('funding_allocations.beneficiary_installment_id', $installment->id)
            ->where('funding_allocations.allocation_kind', '!=', 'reversal')
            ->where('fund_receipts.medium', 'cheque')
            ->groupBy('fund_receipts.id', 'fund_receipts.cheque_number', 'fund_receipts.amount')
            ->havingRaw("SUM({$enPie}) > 0")
            ->havingRaw("SUM({$enPie}) <> fund_receipts.amount")
            ->orderBy('fund_receipts.id')
            ->selectRaw("fund_receipts.cheque_number, fund_receipts.amount, SUM({$enPie}) AS held")
            ->first();

        if ($fila === null) {
            return null;
        }

        return [
            'number' => $fila->cheque_number === null ? null : (string) $fila->cheque_number,
            'amount' => Decimal::scale((string) $fila->amount),
            'held' => Decimal::scale((string) $fila->held),
        ];
    }

    /**
     * La explicación para quien intenta entregarlo, con la salida: el
     * depósito lleva el cheque entero (`InstallmentDeposit`) y la cuota se
     * paga por transferencia.
     *
     * @param  array{number: string|null, amount: numeric-string, held: numeric-string}  $cheque
     */
    public static function message(array $cheque): string
    {
        return sprintf(
            'El cheque n.º %s es de $ %s y esta cuota tiene $ %s de él: un cheque se entrega entero, '
                .'como llegó. Se puede depositar completo y pagar la cuota por transferencia.',
            $cheque['number'] ?? '(sin número)',
            Decimal::format($cheque['amount']),
            Decimal::format($cheque['held']),
        );
    }
}
