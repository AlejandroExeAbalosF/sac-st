<?php

declare(strict_types=1);

namespace App\Modules\Multas\Support;

use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Modules\Multas\Data\FineBankCreditData;
use App\Modules\Shared\Models\CashBox;
use App\Support\Database\Like;
use App\Support\Money\Decimal;
use Illuminate\Database\Eloquent\Builder;

/**
 * Los créditos viejos de la cuenta de Multas que pueden respaldar un pago
 * anterior al sistema.
 *
 * Son los de la cuenta con la que opera la caja, anteriores a su apertura,
 * sin imputar en ningún libro y sin otro pago anterior vigente que los use.
 * La base repite cada condición (`fine_historical_bank_credit`); esto es
 * lo que ofrece la pantalla.
 */
final class FineBankCredits
{
    /** La apertura en pesos de la caja de Multas, con su cuenta, si ya se hizo. */
    public function book(): ?CashBookOpening
    {
        $caja = CashBox::query()->where('code', CashBox::MULTAS)->value('id');

        if ($caja === null) {
            return null;
        }

        $apertura = CashBookOpening::for((int) $caja, Currency::Ars);

        return $apertura?->bank_account_id === null ? null : $apertura;
    }

    /**
     * @return list<FineBankCreditData>
     */
    public function search(string $termino, int $limite = 20): array
    {
        $apertura = $this->book();

        if ($apertura === null) {
            return [];
        }

        $termino = trim($termino);
        // «280.000,00» o «280000» buscan ese importe exacto.
        $importe = $termino === '' ? null : Decimal::parse($termino);

        return array_values($this->eligible($apertura)
            ->when($termino !== '', function (Builder $query) use ($termino, $importe): void {
                $query->where(function (Builder $o) use ($termino, $importe): void {
                    $o->where('description', 'ilike', Like::contains($termino))
                        ->orWhere('operation_id', 'ilike', Like::contains($termino))
                        ->orWhere('counterparty_name', 'ilike', Like::contains($termino))
                        ->orWhere('counterparty_identifier', 'ilike', Like::contains($termino))
                        ->when($importe !== null, fn (Builder $q) => $q->orWhere('amount', $importe));
                });
            })
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit($limite)
            ->get()
            ->map(fn (BankTransaction $credito): FineBankCreditData => FineBankCreditData::fromModel($credito))
            ->all());
    }

    /**
     * @return Builder<BankTransaction>
     */
    public function eligible(CashBookOpening $apertura): Builder
    {
        return BankTransaction::query()
            ->where('bank_account_id', $apertura->bank_account_id)
            ->where('direction', TransactionDirection::Credit->value)
            ->where('transaction_date', '<', $apertura->opened_on->toDateString())
            ->whereIn('reconciliation_status', [ReconciliationStatus::Pending->value, ReconciliationStatus::Ignored->value])
            ->whereRaw('(SELECT COALESCE(SUM(CASE WHEN a.reversal_of_id IS NULL THEN a.amount ELSE -a.amount END), 0)
                FROM bank_transaction_allocations a WHERE a.bank_transaction_id = bank_transactions.id) = 0')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('fine_historical_movements')
                ->whereColumn('fine_historical_movements.bank_transaction_id', 'bank_transactions.id')
                ->where('fine_historical_movements.status', '<>', 'rejected'));
    }
}
