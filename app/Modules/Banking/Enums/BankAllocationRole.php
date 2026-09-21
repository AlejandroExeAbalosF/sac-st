<?php

declare(strict_types=1);

namespace App\Modules\Banking\Enums;

/**
 * Qué explica un movimiento bancario — §9.3 del DER.
 *
 * La imputación no dice «este movimiento es esto»: dice **qué hecho
 * contable confirma**. La diferencia importa porque un mismo crédito puede
 * respaldar más de una cosa, y porque un movimiento sin rol asignado es
 * justamente lo que el área tiene que revisar.
 */
enum BankAllocationRole: string
{
    /** Un crédito que es dinero que entró para un beneficiario. */
    case FundsReceived = 'funds_received';

    /** Un débito que confirma que se pagó por transferencia. */
    case PaymentConfirmation = 'payment_confirmation';

    /** Un crédito que confirma que el efectivo llegó al banco. */
    case CashDepositConfirmation = 'cash_deposit_confirmation';

    public function label(): string
    {
        return match ($this) {
            self::FundsReceived => 'Recepción de fondos',
            self::PaymentConfirmation => 'Confirmación de pago',
            self::CashDepositConfirmation => 'Acreditación de depósito',
        };
    }

    /** Si el rol espera un crédito o un débito en el extracto. */
    public function expectsCredit(): bool
    {
        return $this !== self::PaymentConfirmation;
    }
}
