<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Support;

use App\Modules\Aranceles\Models\FeeCharge;
use App\Modules\Aranceles\Models\FeeChargeFunding;
use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Support\Reason;
use Carbon\CarbonInterface;
use RuntimeException;

/** Lo que un cobro hace con su dinero: vincularlo, aplicarlo y liberarlo. */
final class FeeChargeFunds
{
    public function __construct(
        private readonly PostJournalEntry $asentar,
        private readonly FeeEventReversal $reversion,
    ) {}

    public function cashBoxId(): int
    {
        return (int) CashBox::query()->where('code', CashBox::ARANCELES)->valueOrFail('id');
    }

    /** Vincula la recepción y aplica el importe: pasa de sin identificar a recaudado. */
    public function apply(FeeCharge $cobro, FundReceipt $recepcion, string $importe, CarbonInterface $fecha, ?int $actorId): FinancialEvent
    {
        $caja = $this->cashBoxId();

        $vinculo = FeeChargeFunding::query()->create([
            'fee_charge_id' => $cobro->id,
            'fund_receipt_id' => $recepcion->id,
            'amount' => $importe,
            'created_by' => $actorId,
        ]);

        $evento = $this->asentar->handle(
            type: FinancialEventType::FundsApplied,
            idempotencyKey: 'arancel-cobro-'.$cobro->id.'-aplicacion-'.$vinculo->id,
            lines: [
                EntryLine::debit(LedgerAccount::UnassignedFunds, $importe)->in(Currency::Ars)->onCashBox($caja),
                EntryLine::credit(LedgerAccount::CollectedFunds, $importe)->in(Currency::Ars)->onCashBox($caja),
            ],
            date: $fecha,
            cashBoxId: $caja,
            description: 'Cobro de arancel de '.$cobro->payer_name,
            actorId: $actorId,
        );

        $vinculo->forceFill(['application_event_id' => $evento->id])->save();

        return $evento;
    }

    /**
     * Revierte las aplicaciones de un cobro y libera sus vínculos. El dinero
     * vuelve a estar sin identificar: quien llama decide si revierte también
     * la recepción o la aplica a otro cobro.
     *
     * @return list<int> las recepciones que quedaron libres
     */
    public function release(FeeCharge $cobro, Reason $motivo, ?int $actorId): array
    {
        $recepciones = [];

        foreach (FeeChargeFunding::query()->where('fee_charge_id', $cobro->id)->whereNull('released_at')->orderBy('id')->lockForUpdate()->get() as $vinculo) {
            $reversion = $vinculo->application_event_id === null
                ? null
                : $this->reversion->of($vinculo->application_event_id, 'arancel-vinculo-'.$vinculo->id.'-reversion', $motivo, $actorId);

            $vinculo->forceFill([
                'application_reversal_event_id' => $reversion?->id,
                'released_at' => now(),
                'released_by' => $actorId,
            ])->save();

            $recepciones[] = $vinculo->fund_receipt_id;
        }

        return $recepciones;
    }

    /**
     * Revierte una recepción ya liberada. Con el vínculo vigente, la base lo
     * rechaza (`fund_receipts_keep_fee_fundings`).
     */
    public function reverseReceipt(FundReceipt $recepcion, string $clave, Reason $motivo, ?int $actorId): void
    {
        if ($recepcion->reversed_at !== null) {
            throw new RuntimeException('La recepción ya estaba revertida.');
        }

        $salida = $this->reversion->of($recepcion->financial_event_id, $clave, $motivo, $actorId);

        $recepcion->forceFill([
            'reversal_event_id' => $salida->id,
            'reversed_at' => now(),
            'reversed_by' => $actorId,
            'reversal_reason' => $motivo->describe(),
        ])->save();
    }

    /** Si algo de este efectivo viajó en un depósito que no se canceló. */
    public function isDeposited(int $recepcionId): bool
    {
        return CashToBankTransfer::query()
            ->join('cash_to_bank_transfer_items AS item', 'item.cash_to_bank_transfer_id', '=', 'cash_to_bank_transfers.id')
            ->where('item.fund_receipt_id', $recepcionId)
            ->where('cash_to_bank_transfers.status', '<>', CashTransferStatus::Cancelled)
            ->exists();
    }

    /** Si algo de este efectivo se acreditó en el banco. */
    public function isCredited(int $recepcionId): bool
    {
        return CashToBankTransfer::query()
            ->join('cash_to_bank_transfer_items AS item', 'item.cash_to_bank_transfer_id', '=', 'cash_to_bank_transfers.id')
            ->where('item.fund_receipt_id', $recepcionId)
            ->where('cash_to_bank_transfers.status', CashTransferStatus::BankConfirmed)
            ->exists();
    }
}
