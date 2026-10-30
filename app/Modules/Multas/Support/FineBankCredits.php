<?php

declare(strict_types=1);

namespace App\Modules\Multas\Support;

use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Support\CashBoxBankAccounts;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Modules\Multas\Data\FineBankCreditData;
use App\Modules\Shared\Models\CashBox;
use App\Support\Database\Like;
use App\Support\Money\Decimal;
use Illuminate\Database\Eloquent\Builder;

/**
 * Los créditos de las cuentas de Multas que la pantalla ofrece para respaldar
 * un pago.
 *
 * Una cuenta aporta sus créditos dentro de su vigencia en la caja
 * (`CashBoxBankAccounts`). Que se ofrezcan no los hace de Multas: la cuenta
 * puede ser compartida, y lo decide quien los identifica.
 *
 * - **Viejos** (`search`): anteriores a la apertura de la caja, para un pago
 *   anterior al sistema. La base repite cada condición
 *   (`fine_historical_bank_credit`).
 * - **Disponibles** (`available`): desde la apertura de la caja, para
 *   verificar un pago por el banco. La base repite las suyas
 *   (`bank_allocation_after_opening`, `bank_allocation_follows_box_accounts`,
 *   `fine_payment_application_bank_credit`).
 *
 * En los dos casos, sin imputar en ningún libro y sin un pago anterior
 * vigente que los use.
 */
final class FineBankCredits
{
    public function __construct(private readonly CashBoxBankAccounts $cuentas) {}

    /** La apertura en pesos de la caja de Multas, si ya se hizo y tiene alguna cuenta habilitada. */
    public function book(): ?CashBookOpening
    {
        $caja = CashBox::query()->where('code', CashBox::MULTAS)->value('id');

        if ($caja === null) {
            return null;
        }

        $apertura = CashBookOpening::for((int) $caja, Currency::Ars);

        return $apertura !== null && $this->cuentas->hasAny((int) $caja) ? $apertura : null;
    }

    /**
     * Las cuentas de la caja de Multas que aportan créditos para identificar.
     *
     * @return list<int>
     */
    public function accounts(CashBookOpening $apertura): array
    {
        return $this->cuentas->accountsOf($this->cuentas->identificationWindows((int) $apertura->cash_box_id, Currency::Ars));
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

        return $this->buscar($this->eligible($apertura), $termino, $limite);
    }

    /**
     * Los créditos libres desde la apertura de la caja, para verificar un pago
     * por el banco.
     *
     * @return list<FineBankCreditData>
     */
    public function available(string $termino, int $limite = 20): array
    {
        $apertura = $this->book();

        if ($apertura === null) {
            return [];
        }

        return $this->buscar($this->pending($apertura), $termino, $limite);
    }

    /**
     * Lo que entró a las cuentas de la caja, dentro de la vigencia de cada
     * una y desde la apertura, y nadie identificó todavía.
     *
     * Es también la cola de «pendiente de clasificación de caja» (M08): lo
     * anterior a la apertura ya está en el saldo que el área traía y solo
     * puede respaldar un pago anterior de una multa (`eligible`).
     *
     * @return Builder<BankTransaction>
     */
    public function pending(CashBookOpening $apertura): Builder
    {
        return $this->libres($this->cuentas->within(BankTransaction::query()
            ->where('direction', TransactionDirection::Credit->value)
            ->where('reconciliation_status', ReconciliationStatus::Pending->value), $this->cuentas->identificationWindows((int) $apertura->cash_box_id, Currency::Ars)));
    }

    /**
     * @param  Builder<BankTransaction>  $query
     * @return list<FineBankCreditData>
     */
    private function buscar(Builder $query, string $termino, int $limite): array
    {
        $termino = trim($termino);
        // «280.000,00» o «280000» buscan ese importe exacto.
        $importe = $termino === '' ? null : Decimal::parse($termino);

        return array_values($query
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
        return $this->libres($this->cuentas->within(BankTransaction::query()
            ->where('direction', TransactionDirection::Credit->value)
            ->whereIn('reconciliation_status', [ReconciliationStatus::Pending->value, ReconciliationStatus::Ignored->value]), $this->cuentas->historicalWindows((int) $apertura->cash_box_id, Currency::Ars)));
    }

    /**
     * Sin imputar en ningún libro y sin un pago anterior vigente que los use.
     *
     * @param  Builder<BankTransaction>  $query
     * @return Builder<BankTransaction>
     */
    private function libres(Builder $query): Builder
    {
        return $query
            ->whereRaw('(SELECT COALESCE(SUM(CASE WHEN a.reversal_of_id IS NULL THEN a.amount ELSE -a.amount END), 0)
                FROM bank_transaction_allocations a WHERE a.bank_transaction_id = bank_transactions.id) = 0')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('fine_historical_movements')
                ->whereColumn('fine_historical_movements.bank_transaction_id', 'bank_transactions.id')
                ->where('fine_historical_movements.status', '<>', 'rejected'));
    }
}
