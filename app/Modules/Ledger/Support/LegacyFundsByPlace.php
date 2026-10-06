<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Cuánto del saldo del sistema anterior queda en cada lugar.
 *
 * `LEGACY_FUNDS` es un saldo único, pero la plata vieja está en lugares
 * concretos —el cajón, la cartera de cheques, cada cuenta bancaria— que
 * además guardan plata que entró después. Apartar o pagar plata vieja en
 * efectivo exige que quede plata vieja **en efectivo**: que el cajón tenga
 * billetes por un cobro de hoy, o que el saldo viejo alcance por lo que hay
 * en el banco, no alcanza.
 *
 * Lee la función `legacy_funds_at` de la base, la misma que usa la guarda:
 * el mensaje del Action y el rechazo de la base no pueden dar distinto.
 */
final class LegacyFundsByPlace
{
    public function __construct(private readonly CashBalance $saldos) {}

    /** @return numeric-string */
    public function cash(int $cashBoxId, Currency $currency): string
    {
        return $this->at($cashBoxId, $currency, LedgerAccount::CashOnHand, null);
    }

    /** @return numeric-string */
    public function bankAccount(int $cashBoxId, Currency $currency, int $bankAccountId): string
    {
        return $this->at($cashBoxId, $currency, LedgerAccount::BankAccount, $bankAccountId);
    }

    /**
     * Lo que queda en cada lugar, para mostrarlo.
     *
     * Los cheques son el resto del total: cada papel se controla de a uno,
     * y lo que queda en cheques es lo que no está en el cajón ni en una
     * cuenta.
     *
     * @return array{cash: numeric-string, cheques: numeric-string, banks: array<int, numeric-string>}
     */
    public function breakdown(int $cashBoxId, Currency $currency): array
    {
        $efectivo = $this->cash($cashBoxId, $currency);
        $cuentas = [];
        $resto = Decimal::sub($this->saldos->of(LedgerAccount::LegacyFunds, $cashBoxId, $currency), $efectivo);

        foreach (DB::select('SELECT legacy_funds_bank_accounts(?, ?) AS id', [$cashBoxId, $currency->value]) as $fila) {
            $id = (int) $fila->id;
            $cuentas[$id] = $this->bankAccount($cashBoxId, $currency, $id);
            $resto = Decimal::sub($resto, $cuentas[$id]);
        }

        return ['cash' => $efectivo, 'cheques' => $resto, 'banks' => $cuentas];
    }

    /** @return numeric-string */
    private function at(int $cashBoxId, Currency $currency, LedgerAccount $account, ?int $bankAccountId): string
    {
        $queda = DB::scalar(
            'SELECT legacy_funds_at(?, ?, ?, ?)',
            [$cashBoxId, $currency->value, $account->value, $bankAccountId],
        );

        return Decimal::scale((string) $queda);
    }
}
