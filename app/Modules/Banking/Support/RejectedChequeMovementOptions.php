<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Models\ChequeRejection;
use App\Modules\Ledger\Models\FundReceipt;
use Carbon\CarbonInterface;

/**
 * Los movimientos del extracto que podrían ser el crédito o el débito de un
 * cheque rechazado: de la cuenta del depósito, por el importe del cheque,
 * enteros, sin uso y de fecha posible, del más cercano al depósito al más
 * lejano.
 *
 * Propone, no imputa: la elección es de quien concilia, y la confirma el
 * Action con las mismas reglas.
 */
final class RejectedChequeMovementOptions
{
    private const LIMITE = 25;

    public function __construct(private readonly RejectedChequeMovements $movimientos) {}

    /** @return list<array{id: int, date: string|null, amount: string, description: string|null, operationId: string|null}> */
    public function for(ChequeRejection $rechazo, TransactionDirection $sentido): array
    {
        return $rechazo->transfer === null
            ? []
            : $this->query(
                $rechazo->transfer,
                (string) FundReceipt::query()->whereKey($rechazo->fund_receipt_id)->value('amount'),
                $sentido,
                $this->movimientos->earliest($rechazo, $sentido),
            );
    }

    /**
     * Para el traslado del cheque, antes de que exista el rechazo: el débito
     * que el extracto ya trae cuando el banco rechaza sin aviso previo.
     *
     * @return list<array{id: int, date: string|null, amount: string, description: string|null, operationId: string|null}>
     */
    public function forTransfer(CashToBankTransfer $traslado, string $importe, TransactionDirection $sentido): array
    {
        return $this->query($traslado, $importe, $sentido, $this->movimientos->earliestFor($traslado, $traslado->credit_event_id, $sentido));
    }

    /** @return list<array{id: int, date: string|null, amount: string, description: string|null, operationId: string|null}> */
    private function query(CashToBankTransfer $traslado, string $importe, TransactionDirection $sentido, ?CarbonInterface $desde): array
    {

        /** @var list<array{id: int, date: string|null, amount: string, description: string|null, operationId: string|null}> $movimientos */
        $movimientos = BankTransaction::query()
            ->where('bank_account_id', $traslado->bank_account_id)
            ->where('direction', $sentido->value)
            ->where('reconciliation_status', '!=', ReconciliationStatus::Ignored->value)
            ->whereRaw('ABS(amount) = ?::numeric', [$importe])
            ->whereRaw('0 = (
                SELECT COALESCE(SUM(CASE WHEN reversal_of_id IS NULL THEN amount ELSE -amount END), 0)
                FROM bank_transaction_allocations
                WHERE bank_transaction_id = bank_transactions.id
            )')
            ->whereNotNull('transaction_date')
            ->when($desde !== null, fn ($q) => $q->where('transaction_date', '>=', $desde?->toDateString()))
            ->orderByRaw('ABS(transaction_date - ?::date)', [$traslado->deposit_date->toDateString()])
            ->orderByDesc('id')
            ->limit(self::LIMITE)
            ->get()
            ->map(fn (BankTransaction $m): array => [
                'id' => $m->id,
                'date' => $m->transaction_date?->format('Y-m-d'),
                'amount' => $m->amount,
                'description' => $m->description,
                'operationId' => $m->operation_id,
            ])
            ->all();

        return $movimientos;
    }
}
