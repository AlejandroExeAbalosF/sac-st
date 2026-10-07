<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Haberes\Data\ChequeAssignmentData;
use App\Modules\Haberes\Data\ChequeInCustodyData;
use App\Modules\Haberes\Data\ChequeInventoryData;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Ledger\Enums\ChequeStatus;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Ledger\Support\UndetailedCheques;
use App\Support\Money\Decimal;

/**
 * La cartera de cheques de una caja, con de quién es cada uno.
 *
 * «Cheques en custodia» es un saldo: dice cuánto hay en cheques, no cuáles
 * ni para quién. Esto lo abre: cada papel con su número, de dónde vino y a
 * qué cuota está asignado o reservado —o que todavía no tiene dueño—, y lo
 * que la apertura declaró como total sin detallar.
 *
 * Vive en Haberes y no en Ledger porque contestar «para quién» es hablar de
 * cuotas y expedientes, que Ledger no conoce.
 *
 * Es la cartera **de hoy**: el estado de un cheque es el de ahora, así que
 * no se puede reconstruir cuáles estaban en la caja un día pasado.
 */
final class ChequeInventory
{
    public function __construct(
        private readonly CashBalance $saldos,
        private readonly UndetailedCheques $sinDetallar,
    ) {}

    public function for(int $cashBoxId, Currency $currency): ChequeInventoryData
    {
        $cheques = FundReceipt::query()
            ->with('depositor')
            ->where('medium', PaymentMedium::Cheque->value)
            ->where('cheque_status', ChequeStatus::InCustody->value)
            ->whereNull('reversal_event_id')
            ->where('cash_box_id', $cashBoxId)
            ->where('currency', $currency->value)
            ->orderBy('received_date')
            ->orderBy('id')
            ->get();

        $asignaciones = $this->asignacionesDe(array_map(
            static fn (FundReceipt $cheque): int => $cheque->id,
            $cheques->all(),
        ));

        $lista = [];
        $detallado = '0.00';

        foreach ($cheques as $cheque) {
            $deEste = $asignaciones[$cheque->id] ?? [];
            $asignado = '0.00';
            $filas = [];

            foreach ($deEste as $asignacion) {
                $enPie = Decimal::scale((string) $asignacion->getAttribute('remaining'));
                $asignado = Decimal::add($asignado, $enPie);
                $cuota = $asignacion->installment;
                $haber = $cuota->haber;

                $filas[] = new ChequeAssignmentData(
                    installmentId: $cuota->id,
                    installmentNumber: $cuota->installment_number,
                    expedienteNumber: $haber->expediente->display_number,
                    beneficiaryName: $haber->beneficiary->name,
                    amount: $enPie,
                    reserved: $asignacion->allocationEvent->event_type === FinancialEventType::LegacyFundsAllocated,
                );
            }

            $sinDueño = Decimal::sub(Decimal::scale($cheque->amount), $asignado);

            $lista[] = new ChequeInCustodyData(
                id: $cheque->id,
                number: $cheque->cheque_number,
                bank: $cheque->cheque_bank,
                issueDate: $cheque->cheque_issue_date?->toDateString(),
                amount: Decimal::scale($cheque->amount),
                receivedDate: $cheque->received_date->toDateString(),
                origin: $cheque->origin,
                depositorName: $cheque->depositor?->name,
                assignments: $filas,
                unassigned: Decimal::isNegative($sinDueño) ? '0.00' : $sinDueño,
            );

            $detallado = Decimal::add($detallado, Decimal::scale($cheque->amount));
        }

        return new ChequeInventoryData(
            cheques: $lista,
            detailed: $detallado,
            undetailed: $this->sinDetallar->amount($cashBoxId, $currency),
            total: $this->saldos->of(LedgerAccount::ChequesInCustody, $cashBoxId, $currency),
        );
    }

    /**
     * Las asignaciones en pie de esos cheques, por cheque, cada una con lo
     * que le queda: una liberada del todo ya no es de nadie.
     *
     * @param  array<int>  $receiptIds
     * @return array<int, list<FundingAllocation>>
     */
    private function asignacionesDe(array $receiptIds): array
    {
        if ($receiptIds === []) {
            return [];
        }

        $filas = FundingAllocation::query()
            ->withRemainingBalance()
            ->with(['allocationEvent', 'installment.haber.beneficiary', 'installment.haber.expediente'])
            ->whereIn('fund_receipt_id', $receiptIds)
            ->select('funding_allocations.*')
            ->selectRaw(
                'funding_allocations.amount - COALESCE(('
                .'SELECT SUM(reversions.amount) FROM funding_allocations AS reversions '
                .'WHERE reversions.reversal_of_id = funding_allocations.id'
                .'), 0) AS remaining',
            )
            ->orderBy('funding_allocations.id')
            ->get();

        $porCheque = [];

        foreach ($filas as $asignacion) {
            $porCheque[$asignacion->fund_receipt_id][] = $asignacion;
        }

        return $porCheque;
    }
}
