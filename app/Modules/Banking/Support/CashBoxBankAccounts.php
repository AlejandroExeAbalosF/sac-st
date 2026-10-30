<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashBoxBankAccount;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Models\CashBookOpening;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Las cuentas bancarias habilitadas de una caja y sus ventanas.
 *
 * Una caja sin cuentas vinculadas no se controla —Haberes antes de usar
 * ninguna—; con vínculos, depositar e identificar exigen una cuenta vigente
 * en la fecha. La base lo repite (`cash_transfer_follows_box_accounts`,
 * `bank_allocation_follows_box_accounts`): esto existe para decirlo con
 * palabras y para no ofrecer lo que la base va a rechazar.
 *
 * @phpstan-type Window array{account: int, from: string|null, until: string|null}
 */
final class CashBoxBankAccounts
{
    /**
     * Todos los períodos de las cuentas de la caja, vigentes o dados de baja:
     * la predeterminada primero, después los abiertos, por nombre y por
     * inicio.
     *
     * @return Collection<int, CashBoxBankAccount>
     */
    public function of(int $cashBoxId, ?Currency $currency = null): Collection
    {
        return CashBoxBankAccount::query()
            ->with(['bankAccount', 'creator:id,name'])
            ->where('cash_box_id', $cashBoxId)
            ->when($currency !== null, fn (Builder $q) => $q->where('currency', $currency?->value))
            ->get()
            ->sortBy(fn (CashBoxBankAccount $v): string => ($v->is_default ? '0' : '1').($v->valid_until === null ? '0' : '1').$v->bankAccount->label.($v->valid_from?->toDateString() ?? '0000'))
            ->values();
    }

    /**
     * Las cuentas para elegir al registrar un depósito: un período por fila,
     * con su vigencia, para que el formulario muestre las de la fecha que se
     * carga —un depósito de antes de una baja va a la cuenta dada de baja—.
     * Una caja sin vínculos no se controla: van las cuentas activas, sin
     * vigencia.
     *
     * @return list<array{id: int, label: string, currency: string, isDefault: bool, validFrom: string|null, validUntil: string|null}>
     */
    public function depositOptions(int $cashBoxId, ?Currency $currency = null): array
    {
        if (! $this->hasAny($cashBoxId)) {
            return array_values(BankAccount::query()
                ->where('is_active', true)
                ->when($currency !== null, fn (Builder $q) => $q->where('currency', $currency?->value))
                ->orderBy('label')
                ->get()
                ->map(fn (BankAccount $a): array => [
                    'id' => $a->id, 'label' => $a->label, 'currency' => $a->currency,
                    'isDefault' => false, 'validFrom' => null, 'validUntil' => null,
                ])
                ->all());
        }

        return array_values($this->of($cashBoxId, $currency)->map(fn (CashBoxBankAccount $v): array => [
            'id' => $v->bank_account_id,
            'label' => $v->bankAccount->label,
            'currency' => $v->currency,
            'isDefault' => $v->is_default,
            'validFrom' => $v->valid_from?->toDateString(),
            'validUntil' => $v->valid_until?->toDateString(),
        ])->all());
    }

    /**
     * Toma el candado de la cuenta en la caja hasta el final de la
     * transacción: el mismo que toman las guardas de la base al depositar o
     * identificar. Quien cambia la vigencia lo toma antes de revisar los usos,
     * así espera a la operación en curso y la ve confirmada.
     */
    public function lock(int $cashBoxId, int $bankAccountId): void
    {
        DB::select('SELECT lock_cash_box_account(?, ?)', [$cashBoxId, $bankAccountId]);
    }

    public function hasAny(int $cashBoxId): bool
    {
        return CashBoxBankAccount::query()->where('cash_box_id', $cashBoxId)->exists();
    }

    /**
     * Las vigentes ese día, en esa moneda.
     *
     * @return Collection<int, CashBoxBankAccount>
     */
    public function activeOn(int $cashBoxId, Currency $currency, CarbonInterface $date): Collection
    {
        return $this->of($cashBoxId, $currency)
            ->filter(fn (CashBoxBankAccount $v): bool => $v->covers($date))
            ->values();
    }

    public function defaultFor(int $cashBoxId, Currency $currency): ?CashBoxBankAccount
    {
        return CashBoxBankAccount::query()
            ->with('bankAccount')
            ->where('cash_box_id', $cashBoxId)
            ->where('currency', $currency->value)
            ->where('is_default', true)
            ->first();
    }

    /** Algún período de la cuenta en la caja cubre ese día. */
    public function covers(int $cashBoxId, int $bankAccountId, CarbonInterface $date): bool
    {
        $dia = $date->toDateString();

        return CashBoxBankAccount::query()
            ->where('cash_box_id', $cashBoxId)
            ->where('bank_account_id', $bankAccountId)
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $dia))
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $dia))
            ->exists();
    }

    /**
     * El período de la misma cuenta en la caja que termina justo antes de
     * este: el inicio de este no puede alcanzarlo.
     */
    public function previousPeriod(CashBoxBankAccount $vinculo): ?CashBoxBankAccount
    {
        // Sin inicio es el primero: los demás períodos vienen después.
        if ($vinculo->valid_from === null) {
            return null;
        }

        return CashBoxBankAccount::query()
            ->where('cash_box_id', $vinculo->cash_box_id)
            ->where('bank_account_id', $vinculo->bank_account_id)
            ->whereKeyNot($vinculo->id)
            ->where('valid_until', '<', $vinculo->valid_from->toDateString())
            ->orderByDesc('valid_until')
            ->first();
    }

    /**
     * Desde cuándo y hasta cuándo se ofrece identificar los créditos de cada
     * cuenta: desde la más tardía entre la apertura del libro y el inicio de
     * la vigencia, hasta la baja. Sin apertura no hay ventana.
     *
     * @return list<Window>
     */
    public function identificationWindows(int $cashBoxId, Currency $currency): array
    {
        $apertura = CashBookOpening::for($cashBoxId, $currency)?->opened_on;

        if ($apertura === null) {
            return [];
        }

        return array_values($this->of($cashBoxId, $currency)->map(fn (CashBoxBankAccount $v): array => [
            'account' => $v->bank_account_id,
            'from' => max($apertura->toDateString(), $v->valid_from?->toDateString() ?? ''),
            'until' => $v->valid_until?->toDateString(),
        ])->all());
    }

    /**
     * Lo anterior a la apertura del libro que cae en la vigencia de una
     * cuenta: lo que ya estaba en el saldo que el área traía. Una cuenta con
     * inicio posterior a la apertura no tiene ventana histórica.
     *
     * @return list<Window>
     */
    public function historicalWindows(int $cashBoxId, Currency $currency): array
    {
        $apertura = CashBookOpening::for($cashBoxId, $currency)?->opened_on;

        if ($apertura === null) {
            return [];
        }

        $ultimoDiaPrevio = $apertura->subDay()->toDateString();

        return array_values($this->of($cashBoxId, $currency)
            ->filter(fn (CashBoxBankAccount $v): bool => $v->valid_from === null || $v->valid_from->toDateString() <= $ultimoDiaPrevio)
            ->map(fn (CashBoxBankAccount $v): array => [
                'account' => $v->bank_account_id,
                'from' => $v->valid_from?->toDateString(),
                'until' => min($ultimoDiaPrevio, $v->valid_until?->toDateString() ?? $ultimoDiaPrevio),
            ])
            ->all());
    }

    /**
     * Restringe una consulta de movimientos a esas ventanas. Sin ventanas,
     * no deja pasar nada.
     *
     * @param  Builder<BankTransaction>  $query
     * @param  list<Window>  $windows
     * @return Builder<BankTransaction>
     */
    public function within(Builder $query, array $windows): Builder
    {
        if ($windows === []) {
            return $query->whereRaw('FALSE');
        }

        return $query->where(function (Builder $o) use ($windows): void {
            foreach ($windows as $w) {
                $o->orWhere(function (Builder $q) use ($w): void {
                    $q->where('bank_account_id', $w['account'])
                        ->when($w['from'] !== null, fn (Builder $q) => $q->where('transaction_date', '>=', $w['from']))
                        ->when($w['until'] !== null, fn (Builder $q) => $q->where('transaction_date', '<=', $w['until']));
                });
            }
        });
    }

    /**
     * Las cuentas de esas ventanas.
     *
     * @param  list<Window>  $windows
     * @return list<int>
     */
    public function accountsOf(array $windows): array
    {
        return array_values(array_unique(array_map(fn (array $w): int => $w['account'], $windows)));
    }

    /**
     * El último depósito o identificación del período posterior a esa fecha:
     * una baja anterior lo dejaría afuera. La base lo repite
     * (`cash_box_bank_account_window`), también para lo histórico de cada
     * circuito.
     */
    public function lastUseAfter(CashBoxBankAccount $vinculo, CarbonInterface $date): ?CarbonImmutable
    {
        return $this->uso($vinculo, '>', $date->toDateString(), 'max');
    }

    /** El primero del período anterior a esa fecha: un inicio posterior lo dejaría afuera. Sin fecha, ninguno. */
    public function firstUseBefore(CashBoxBankAccount $vinculo, ?CarbonInterface $date): ?CarbonImmutable
    {
        return $date === null ? null : $this->uso($vinculo, '<', $date->toDateString(), 'min');
    }

    /** Lo usado dentro del período de ese vínculo, del lado de la fecha que se mira. */
    private function uso(CashBoxBankAccount $vinculo, string $comparar, string $dia, string $extremo): ?CarbonImmutable
    {
        $desde = $vinculo->valid_from?->toDateString();
        $hasta = $vinculo->valid_until?->toDateString();

        $depositos = DB::table('cash_to_bank_transfers')
            ->where('cash_box_id', $vinculo->cash_box_id)
            ->where('bank_account_id', $vinculo->bank_account_id)
            ->where('deposit_date', $comparar, $dia)
            ->when($desde !== null, fn (QueryBuilder $q) => $q->where('deposit_date', '>=', $desde))
            ->when($hasta !== null, fn (QueryBuilder $q) => $q->where('deposit_date', '<=', $hasta))
            ->selectRaw($extremo === 'max' ? 'max(deposit_date) AS dia' : 'min(deposit_date) AS dia');

        $identificaciones = DB::table('bank_transaction_allocations AS a')
            ->join('bank_transactions AS t', 't.id', '=', 'a.bank_transaction_id')
            ->join('financial_events AS e', 'e.id', '=', 'a.financial_event_id')
            ->where('e.cash_box_id', $vinculo->cash_box_id)
            ->where('e.event_type', FinancialEventType::FundsReceived->value)
            ->whereNull('a.reversal_of_id')
            ->where('t.bank_account_id', $vinculo->bank_account_id)
            ->where('t.transaction_date', $comparar, $dia)
            ->when($desde !== null, fn (QueryBuilder $q) => $q->where('t.transaction_date', '>=', $desde))
            ->when($hasta !== null, fn (QueryBuilder $q) => $q->where('t.transaction_date', '<=', $hasta))
            ->selectRaw($extremo === 'max' ? 'max(t.transaction_date) AS dia' : 'min(t.transaction_date) AS dia');

        $dias = array_filter([$depositos->value('dia'), $identificaciones->value('dia')], fn (mixed $d): bool => is_string($d));

        if ($dias === []) {
            return null;
        }

        return CarbonImmutable::parse($extremo === 'max' ? max($dias) : min($dias));
    }
}
