<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Support;

use App\Modules\Aranceles\Models\FeeCharge;
use App\Modules\Aranceles\Models\FeeChargeFunding;
use App\Modules\Banking\Actions\ReleaseFundReceiptAllocations;
use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\BankTransactionAllocation;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Support\Reason;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/** Lo que un cobro hace con su dinero: vincularlo, aplicarlo y liberarlo. */
final class FeeChargeFunds
{
    public function __construct(
        private readonly PostJournalEntry $asentar,
        private readonly FeeEventReversal $reversion,
        private readonly ReleaseFundReceiptAllocations $liberar,
    ) {}

    public function cashBoxId(): int
    {
        return (int) CashBox::query()->where('code', CashBox::ARANCELES)->valueOrFail('id');
    }

    /** Vincula la recepción y aplica el importe: pasa de sin identificar a recaudado. */
    public function apply(FeeCharge $cobro, FundReceipt $recepcion, string $importe, CarbonInterface $fecha, ?int $actorId): FinancialEvent
    {
        return $this->applyLinked($this->link($cobro, $recepcion, $importe, $actorId), $fecha, $actorId);
    }

    /** Reserva ese dinero para el cobro: sigue sin identificar hasta aplicarlo. */
    public function link(FeeCharge $cobro, FundReceipt $recepcion, string $importe, ?int $actorId): FeeChargeFunding
    {
        return FeeChargeFunding::query()->create([
            'fee_charge_id' => $cobro->id,
            'fund_receipt_id' => $recepcion->id,
            'amount' => $importe,
            'created_by' => $actorId,
        ]);
    }

    public function applyLinked(FeeChargeFunding $vinculo, CarbonInterface $fecha, ?int $actorId): FinancialEvent
    {
        $caja = $this->cashBoxId();
        $importe = (string) $vinculo->amount;

        $evento = $this->asentar->handle(
            type: FinancialEventType::FundsApplied,
            idempotencyKey: 'arancel-cobro-'.$vinculo->fee_charge_id.'-aplicacion-'.$vinculo->id,
            lines: [
                EntryLine::debit(LedgerAccount::UnassignedFunds, $importe)->in(Currency::Ars)->onCashBox($caja),
                EntryLine::credit(LedgerAccount::CollectedFunds, $importe)->in(Currency::Ars)->onCashBox($caja),
            ],
            date: $fecha,
            cashBoxId: $caja,
            description: 'Cobro de arancel de '.FeeCharge::query()->whereKey($vinculo->fee_charge_id)->value('payer_name'),
            actorId: $actorId,
        );

        $vinculo->forceFill(['application_event_id' => $evento->id])->save();

        return $evento;
    }

    /**
     * Toma los créditos del extracto que respaldan el cobro. Va antes que las
     * recepciones: identificar los bloquea en ese orden, y al revés se trabarían.
     *
     * @return list<int> los créditos bloqueados
     */
    public function lockCreditsOf(FeeCharge $cobro): array
    {
        return array_values(BankTransaction::query()
            ->whereIn('id', $this->creditsOf($cobro))
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->all());
    }

    /**
     * Los números de cuenta que van en el papel de un cobro por el banco.
     *
     * @param  iterable<int>  $recepciones
     */
    public function accountNumbersOf(iterable $recepciones): ?string
    {
        $eventos = FundReceipt::query()->whereIn('id', collect($recepciones)->all())->select('financial_event_id');
        $numeros = BankAccount::query()
            ->whereIn('id', BankTransaction::query()
                ->whereIn('id', BankTransactionAllocation::query()->whereIn('financial_event_id', $eventos)->select('bank_transaction_id'))
                ->select('bank_account_id'))
            ->orderBy('account_number')
            ->pluck('account_number')
            ->all();

        return $numeros === [] ? null : implode(' y ', $numeros);
    }

    /** @return Builder<BankTransactionAllocation> */
    private function creditsOf(FeeCharge $cobro): Builder
    {
        return BankTransactionAllocation::query()
            ->whereNull('reversal_of_id')
            ->whereIn('financial_event_id', FundReceipt::query()
                ->whereIn('id', FeeChargeFunding::query()->where('fee_charge_id', $cobro->id)->whereNull('released_at')->select('fund_receipt_id'))
                ->select('financial_event_id'))
            ->select('bank_transaction_id');
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
     * rechaza (`fund_receipts_keep_fee_fundings`). Si vino del banco, el crédito
     * vuelve a quedar libre en el extracto.
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
            'reversal_reason_code' => $motivo->code->value,
        ])->save();

        if ($recepcion->medium === PaymentMedium::Bank) {
            $this->liberar->handle($recepcion, $salida->id, $motivo->describe(), $actorId);
        }
    }

    /** Si algo de este efectivo viajó en un depósito que no se canceló. */
    public function isDeposited(int $recepcionId): bool
    {
        return $this->depositedAmong([$recepcionId]) !== [];
    }

    /**
     * De estas recepciones, las que viajaron en un depósito que no se canceló.
     *
     * @param  list<int>  $recepciones
     * @return list<int>
     */
    public function depositedAmong(array $recepciones): array
    {
        return array_values(CashToBankTransfer::query()
            ->join('cash_to_bank_transfer_items AS item', 'item.cash_to_bank_transfer_id', '=', 'cash_to_bank_transfers.id')
            ->whereIn('item.fund_receipt_id', $recepciones)
            ->where('cash_to_bank_transfers.status', '<>', CashTransferStatus::Cancelled)
            ->distinct()
            ->pluck('item.fund_receipt_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
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
