<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use App\Modules\Multas\Enums\FineDebtStatus;
use App\Modules\Multas\Models\FineBalance;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * El saldo de una multa, como lo calcula la vista `fine_balances`.
 *
 * Los importes viajan como texto: el front no hace cuentas con ellos.
 */
#[TypeScript]
final class FineBalanceData extends Data
{
    public function __construct(
        /** @var numeric-string */
        public string $obligation,
        /** @var numeric-string */
        public string $paid,
        /** @var numeric-string */
        public string $historicalPaid,
        /** @var numeric-string */
        public string $debt,
        /** @var numeric-string */
        public string $excess,
        public FineDebtStatus $status,
        /**
         * Lo aplicado con un cheque rechazado que la empresa todavía no
         * reemplazó (M03). No cambia la deuda: se muestra aparte.
         *
         * @var numeric-string
         */
        public string $pendingRegularization = '0.00',
    ) {}

    /**
     * Toda multa tiene su fila en la vista; `null` solo llega si alguien
     * olvidó cargar la relación, y entonces se ve como una multa en cero.
     */
    public static function fromModel(?FineBalance $saldo): self
    {
        if ($saldo === null) {
            return new self('0.00', '0.00', '0.00', '0.00', '0.00', FineDebtStatus::WithoutEffect);
        }

        return new self(
            obligation: $saldo->obligation,
            paid: $saldo->operational_paid,
            historicalPaid: $saldo->historical_paid,
            debt: $saldo->debt,
            excess: $saldo->excess,
            status: FineDebtStatus::of($saldo->obligation, $saldo->debt),
            pendingRegularization: $saldo->pending_regularization,
        );
    }
}
