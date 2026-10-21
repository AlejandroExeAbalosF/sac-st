<?php

declare(strict_types=1);

namespace App\Modules\Multas\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Cómo pagó el sancionado (M02).
 *
 * El origen judicial no es un medio: una orden de pago judicial llega como
 * transferencia o como cheque, y se marca aparte.
 */
#[TypeScript]
enum FinePaymentMedium: string
{
    case Cash = 'cash';
    case Cheque = 'cheque';
    case BankTransfer = 'bank_transfer';
    case DirectDeposit = 'direct_deposit';
    case Echeq = 'echeq';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Efectivo',
            self::Cheque => 'Cheque',
            self::BankTransfer => 'Transferencia',
            self::DirectDeposit => 'Depósito directo',
            self::Echeq => 'Cheque electrónico',
        };
    }

    /** Se cobra en la caja, en el momento: reduce la deuda al recibirlo. */
    public function isCounter(): bool
    {
        return $this === self::Cash || $this === self::Cheque;
    }
}
