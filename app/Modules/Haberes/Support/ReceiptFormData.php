<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Shared\Models\Receipt;
use App\Modules\Shared\Pdf\ReceiptPrintData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lo que el formulario pide y el comprobante no guarda.
 *
 * Los dos papeles —el de ingreso y el de egreso— tienen renglones que no
 * viven en `receipts`: el número de operación del depósito, los datos del
 * cheque, cuántas cuotas tiene el haber. Se arman acá porque **Haberes es
 * el único módulo que puede mirar a la vez el circuito, el banco y el
 * comprobante**: `Shared`, donde vive `ReceiptPrintData`, no ve ninguna de
 * las dos cosas.
 *
 * Vive suelto y no en un controlador porque lo consultan tres pantallas
 * distintas —emitir, previsualizar, reimprimir— y de los dos tipos de
 * comprobante. Cuando estaba adentro de `HaberController`, el recibo de
 * egreso no tenía cómo llegar a él sin duplicarlo, y dos consultas que
 * arman el mismo papel terminan armándolo distinto.
 */
final class ReceiptFormData
{
    public function forInstallment(BeneficiaryInstallment $installment): ReceiptPrintData
    {
        /** @var object{account_number: string|null, operation_id: string|null, cheque_number: string|null, cheque_bank: string|null, notes: string|null}|null $origen */
        $origen = DB::table('funding_allocations as fa')
            ->join('fund_receipts as fr', 'fr.id', '=', 'fa.fund_receipt_id')
            ->leftJoin('bank_transaction_allocations as bta', 'bta.financial_event_id', '=', 'fr.financial_event_id')
            ->leftJoin('bank_transactions as bt', 'bt.id', '=', 'bta.bank_transaction_id')
            ->leftJoin('bank_accounts as ba', 'ba.id', '=', 'bt.bank_account_id')
            ->where('fa.beneficiary_installment_id', $installment->id)
            ->select([
                'ba.account_number',
                'bt.operation_id',
                'fr.cheque_number',
                'fr.cheque_bank',
                'fr.notes',
            ])
            ->first();

        /*
         * El día del crédito en el extracto: la fecha del movimiento, que es
         * la del ticket o la transferencia, no la de valor. Si la cuota se
         * cubrió con varios créditos, el último: es el que la completó, y
         * sin él no había recibo.
         *
         * Cuenta solo lo que sigue asignado: un crédito desasignado ya no
         * financia la cuota. Si no queda nada asignado —la reimpresión de un
         * recibo anulado, que deshizo la conciliación—, la del historial.
         */
        $fechaDeposito = $this->fechaDelCredito($installment, soloAsignado: true)
            ?? $this->fechaDelCredito($installment, soloAsignado: false);

        return new ReceiptPrintData(
            cuenta: $origen->account_number ?? null,
            numeroOperacion: $origen->operation_id ?? null,
            fechaDeposito: is_string($fechaDeposito) ? CarbonImmutable::parse($fechaDeposito) : null,
            chequeNumero: $origen->cheque_number ?? null,
            chequeBanco: $origen->cheque_bank ?? null,
            cuotaNumero: $installment->installment_number,
            /*
             * Cuántas cuotas reconoce el haber, no cuántas hay cargadas:
             * el papel dice «Cuota 5 de 5» desde la primera, porque el
             * plan lo define el expediente.
             */
            cuotaTotal: $installment->haber->expected_installment_count
                ?? $installment->haber->installments()->count(),
            observaciones: $origen->notes ?? null,
        );
    }

    /** El día del último crédito del extracto detrás de las asignaciones de la cuota. */
    private function fechaDelCredito(BeneficiaryInstallment $installment, bool $soloAsignado): ?string
    {
        $fecha = FundingAllocation::query()
            ->when($soloAsignado, fn ($q) => $q->withRemainingBalance(), fn ($q) => $q->live())
            ->join('fund_receipts as fr', 'fr.id', '=', 'funding_allocations.fund_receipt_id')
            ->join('bank_transaction_allocations as bta', 'bta.financial_event_id', '=', 'fr.financial_event_id')
            ->join('bank_transactions as bt', 'bt.id', '=', 'bta.bank_transaction_id')
            ->where('funding_allocations.beneficiary_installment_id', $installment->id)
            ->whereNull('bta.reversal_of_id')
            ->max('bt.transaction_date');

        return is_string($fecha) ? $fecha : null;
    }

    /** Los renglones que el papel toma de la cuota y no de `receipts`. */
    public function forReceipt(Receipt $receipt): ?ReceiptPrintData
    {
        $cuota = $receipt->beneficiary_installment_id === null
            ? null
            : BeneficiaryInstallment::query()->find($receipt->beneficiary_installment_id);

        return $cuota === null ? null : $this->forInstallment($cuota);
    }
}
