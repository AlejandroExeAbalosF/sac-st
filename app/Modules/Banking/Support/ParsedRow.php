<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Enums\ParseStatus;
use App\Modules\Banking\Enums\TransactionDirection;

/**
 * Una fila del extracto ya interpretada, todavía sin tocar la base.
 *
 * Los importes viajan como cadena decimal y en valor absoluto: el sentido
 * está en `direction`. Es la misma forma que tiene la columna
 * `numeric(19,2)` con `CHECK (amount > 0)` del otro lado.
 */
final class ParsedRow
{
    /**
     * @param  list<string>  $raw  la fila tal como vino del archivo
     */
    public function __construct(
        public readonly int $rowNumber,
        public readonly array $raw,
        public readonly ParseStatus $status,
        public readonly ?string $errorMessage = null,
        public readonly ?string $transactionDate = null,
        /** @var numeric-string|null */
        public readonly ?string $amount = null,
        public readonly ?TransactionDirection $direction = null,
        public readonly ?string $operationId = null,
        public readonly ?string $causalCode = null,
        public readonly ?string $description = null,
        public readonly ?string $counterpartyName = null,
        public readonly ?string $counterpartyIdentifier = null,
        /** @var numeric-string|null */
        public readonly ?string $balanceAfter = null,
    ) {}

    /**
     * @param  list<string>  $raw
     */
    public static function rejected(int $rowNumber, array $raw, string $reason): self
    {
        return new self(
            rowNumber: $rowNumber,
            raw: $raw,
            status: ParseStatus::Rejected,
            errorMessage: $reason,
        );
    }

    public function isUsable(): bool
    {
        return $this->status !== ParseStatus::Rejected;
    }

    /** Lo que efectivamente cambió el saldo: negativo si fue un débito. */
    public function signedAmount(): ?string
    {
        if ($this->amount === null || $this->direction === null) {
            return null;
        }

        return $this->direction === TransactionDirection::Debit
            ? '-'.$this->amount
            : $this->amount;
    }
}
