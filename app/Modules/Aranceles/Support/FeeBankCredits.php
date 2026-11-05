<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Support;

use App\Modules\Aranceles\Data\FeeBankCreditData;
use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Support\AllocatableAmount;
use App\Modules\Banking\Support\CashBoxBankAccounts;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Support\Database\Like;
use App\Support\Money\Decimal;
use Illuminate\Database\Eloquent\Builder;

/**
 * Los créditos que se pueden identificar en Aranceles: de sus cuentas, desde la
 * apertura de la caja y dentro de la vigencia de cada cuenta (regla A58).
 *
 * Un crédito usado en parte sigue ofreciéndose por lo que le queda libre (A54).
 * Que se ofrezca no lo hace de Aranceles: la cuenta es compartida.
 */
final class FeeBankCredits
{
    public function __construct(
        private readonly CashBoxBankAccounts $cuentas,
        private readonly AllocatableAmount $libre,
        private readonly FeeChargeFunds $fondos,
    ) {}

    public function book(): ?CashBookOpening
    {
        $caja = $this->fondos->cashBoxId();
        $apertura = CashBookOpening::for($caja, Currency::Ars);

        return $apertura !== null && $this->cuentas->hasAny($caja) ? $apertura : null;
    }

    /** @return list<int> */
    public function accounts(CashBookOpening $apertura): array
    {
        return $this->cuentas->accountsOf($this->cuentas->identificationWindows((int) $apertura->cash_box_id, Currency::Ars));
    }

    /** @return Builder<BankTransaction> */
    public function identifiable(CashBookOpening $apertura): Builder
    {
        return $this->cuentas->within(BankTransaction::query()
            ->where('direction', TransactionDirection::Credit->value)
            ->whereIn('reconciliation_status', [ReconciliationStatus::Pending->value, ReconciliationStatus::Partial->value]), $this->cuentas->identificationWindows((int) $apertura->cash_box_id, Currency::Ars));
    }

    /**
     * Para elegir uno o más: por importe, operación, descripción o quien depositó.
     *
     * @return list<FeeBankCreditData>
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

        return array_values($this->identifiable($apertura)
            ->with('account')
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
            ->map(fn (BankTransaction $credito): FeeBankCreditData => $this->data($credito))
            ->all());
    }

    public function data(BankTransaction $credito): FeeBankCreditData
    {
        return new FeeBankCreditData(
            id: $credito->id,
            date: (string) $credito->transaction_date?->toDateString(),
            amount: Decimal::abs($credito->amount),
            free: $this->libre->for($credito),
            description: $credito->description,
            counterparty: $credito->counterparty_name,
            operationId: $credito->operation_id,
            accountLabel: $credito->account->label,
        );
    }
}
