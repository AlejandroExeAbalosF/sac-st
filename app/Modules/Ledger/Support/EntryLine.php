<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Support\Money\Decimal;
use InvalidArgumentException;

/**
 * Una línea de asiento, antes de escribirse.
 *
 * Existe para que armar un asiento se lea como se lee un asiento:
 *
 * ```php
 * EntryLine::debit(LedgerAccount::BankAccount, $importe)->onBankAccount($id),
 * EntryLine::credit(LedgerAccount::UnassignedFunds, $importe)->from($personaId),
 * ```
 *
 * El lado se elige en el constructor y no se puede cambiar después: una
 * línea nace débito o crédito. Es la misma restricción que impone el
 * `CHECK` de la tabla, dicha en el tipo.
 */
final readonly class EntryLine
{
    /**
     * @param  numeric-string  $debit
     * @param  numeric-string  $credit
     */
    private function __construct(
        public LedgerAccount $account,
        public string $debit,
        public string $credit,
        /*
         * En qué está denominada la línea.
         *
         * Pesos salvo que se diga lo contrario, que es lo que el sistema
         * viene haciendo desde siempre. El asiento balancea **por moneda**
         * —lo exige el trigger de la base—, así que un asiento que mezcla
         * las dos necesita que cada mitad cierre por su cuenta.
         */
        public Currency $currency = Currency::Ars,
        public ?int $cashBoxId = null,
        public ?int $bankAccountId = null,
        public ?int $depositorId = null,
        public ?int $haberId = null,
        public ?int $beneficiaryInstallmentId = null,
        public ?string $description = null,
    ) {}

    public static function debit(LedgerAccount $account, string $amount): self
    {
        return new self($account, self::positive($amount), '0.00');
    }

    public static function credit(LedgerAccount $account, string $amount): self
    {
        return new self($account, '0.00', self::positive($amount));
    }

    /** La moneda de la línea. Sin esto, pesos. */
    public function in(Currency $currency): self
    {
        return $this->with(currency: $currency);
    }

    public function onCashBox(?int $cashBoxId): self
    {
        return $this->with(cashBoxId: $cashBoxId);
    }

    public function onBankAccount(?int $bankAccountId): self
    {
        return $this->with(bankAccountId: $bankAccountId);
    }

    /** Quién puso el dinero. Solo tiene sentido en las líneas de ingreso. */
    public function from(?int $depositorId): self
    {
        return $this->with(depositorId: $depositorId);
    }

    /** De quién es. Las dos dimensiones de `BENEFICIARY_FUNDS`. */
    public function forInstallment(?int $haberId, ?int $installmentId): self
    {
        return $this->with(haberId: $haberId, beneficiaryInstallmentId: $installmentId);
    }

    public function describedAs(?string $description): self
    {
        return $this->with(description: $description);
    }

    /** El importe, esté de un lado o del otro. */
    public function amount(): string
    {
        return Decimal::equals($this->debit, '0') ? $this->credit : $this->debit;
    }

    public function isDebit(): bool
    {
        return ! Decimal::equals($this->debit, '0');
    }

    /** @return array<string, string|int|null> */
    public function toRow(int $financialEventId): array
    {
        return [
            'financial_event_id' => $financialEventId,
            'account_code' => $this->account->value,
            'currency' => $this->currency->value,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'cash_box_id' => $this->cashBoxId,
            'bank_account_id' => $this->bankAccountId,
            'depositor_id' => $this->depositorId,
            'haber_id' => $this->haberId,
            'beneficiary_installment_id' => $this->beneficiaryInstallmentId,
            'description' => $this->description,
        ];
    }

    private function with(
        ?Currency $currency = null,
        ?int $cashBoxId = null,
        ?int $bankAccountId = null,
        ?int $depositorId = null,
        ?int $haberId = null,
        ?int $beneficiaryInstallmentId = null,
        ?string $description = null,
    ): self {
        return new self(
            account: $this->account,
            debit: $this->debit,
            credit: $this->credit,
            currency: $currency ?? $this->currency,
            cashBoxId: $cashBoxId ?? $this->cashBoxId,
            bankAccountId: $bankAccountId ?? $this->bankAccountId,
            depositorId: $depositorId ?? $this->depositorId,
            haberId: $haberId ?? $this->haberId,
            beneficiaryInstallmentId: $beneficiaryInstallmentId ?? $this->beneficiaryInstallmentId,
            description: $description ?? $this->description,
        );
    }

    /**
     * Un asiento no mueve cero ni importes negativos.
     *
     * El signo lo dice el lado —débito o crédito—, nunca el número. Un
     * importe negativo en una línea significaría lo mismo que uno positivo
     * del otro lado, y dos maneras de escribir lo mismo son una manera de
     * más para que los saldos no coincidan.
     *
     * @return numeric-string
     */
    private static function positive(string $amount): string
    {
        $scaled = Decimal::scale($amount);

        if (Decimal::isNegative($scaled) || Decimal::equals($scaled, '0')) {
            throw new InvalidArgumentException(
                "Una línea del asiento no puede ser de {$scaled}: el lado lo da el débito o el crédito."
            );
        }

        return $scaled;
    }
}
