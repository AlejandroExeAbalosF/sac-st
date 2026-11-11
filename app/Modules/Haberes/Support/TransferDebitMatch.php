<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Banking\Models\BankTransaction;
use App\Support\Money\Decimal;

/**
 * Lo que `TransferDebitPair` concluyó sobre un débito y una Orden.
 *
 * Tres resultados, no dos:
 *
 * - **coincide**: la transferencia —y su comisión, si la hay— cierra
 *   exacto con la Orden y los movimientos están enteros y libres;
 * - **para revisión**: el importe cierra, pero el extracto no alcanza para
 *   afirmarlo solo —dos comisiones posibles, o la única quedó fuera del
 *   circuito—. Se muestra, pero no se puede elegir;
 * - **no corresponde**: es otro pago, y ni se ofrece.
 */
final readonly class TransferDebitMatch
{
    public function __construct(
        public BankTransaction $transfer,
        public ?BankTransaction $fee = null,
        /** Por qué no es este pago. */
        public ?string $problem = null,
        /** Por qué cierra pero necesita que alguien lo mire. */
        public ?string $reviewReason = null,
    ) {}

    public function matches(): bool
    {
        return $this->problem === null && $this->reviewReason === null;
    }

    public function needsReview(): bool
    {
        return $this->problem === null && $this->reviewReason !== null;
    }

    /** Lo que el beneficiario recibió en su cuenta. @return numeric-string */
    public function transferAmount(): string
    {
        return Decimal::abs($this->transfer->amount);
    }

    /** @return numeric-string|null */
    public function feeAmount(): ?string
    {
        return $this->fee === null ? null : Decimal::abs($this->fee->amount);
    }

    /** Lo que salió de la cuenta por este pago. @return numeric-string */
    public function total(): string
    {
        return Decimal::add($this->transferAmount(), $this->feeAmount() ?? '0');
    }

    /** La primera razón por la que no se puede vincular, si hay alguna. */
    public function rejection(): ?string
    {
        return $this->problem ?? $this->reviewReason;
    }
}
