<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

/**
 * Los hechos que mueven dinero — §9.4 del DER.
 *
 * Cada uno tiene un asiento fijo en el §10, y esa correspondencia es lo
 * que hace auditable al sistema: sabiendo el tipo de evento se sabe qué
 * cuentas tocó, sin leer las líneas.
 *
 * Están los once desde el principio aunque la tanda del motor solo use
 * dos. No es previsión ociosa: el tipo viaja en un `CHECK` de la base, y
 * ampliarlo después obliga a una migración sobre una tabla append-only
 * que para entonces ya tendría hechos reales.
 */
enum FinancialEventType: string
{
    /** Entró dinero: transferencia, depósito, efectivo o cheque. */
    case FundsReceived = 'funds_received';

    /** Ese dinero dejó de ser anónimo y quedó imputado a una cuota. */
    case FundsAllocated = 'funds_allocated';

    /** Pago al beneficiario en efectivo por ventanilla. */
    case CashDisbursement = 'cash_disbursement';

    /** El efectivo salió de la caja camino al banco. */
    case CashDepositedToBank = 'cash_deposited_to_bank';

    /** Pago al beneficiario por transferencia. */
    case BankDisbursement = 'bank_disbursement';

    /** El banco acreditó lo que estaba en tránsito. Cierra la ventana. */
    case CashDepositCredited = 'cash_deposit_credited';

    /** Diferencia de arqueo, que exige explicación (invariante 28). */
    case CashAdjustment = 'cash_adjustment';

    /** El saldo con el que el sistema empieza a contar. */
    case OpeningBalance = 'opening_balance';

    /** Pago de un haber que viene del sistema anterior. */
    case LegacyDisbursement = 'legacy_disbursement';

    /** La contracara de un evento anterior. No lo borra: lo resta. */
    case Reversal = 'reversal';

    /** Corrección autorizada, con responsable y motivo. */
    case AuthorizedAdjustment = 'authorized_adjustment';

    public function label(): string
    {
        return match ($this) {
            self::FundsReceived => 'Recepción de fondos',
            self::FundsAllocated => 'Asignación a cuota',
            self::CashDisbursement => 'Pago en efectivo',
            self::CashDepositedToBank => 'Depósito en el banco',
            self::BankDisbursement => 'Pago por transferencia',
            self::CashDepositCredited => 'Acreditación del depósito',
            self::CashAdjustment => 'Ajuste de arqueo',
            self::OpeningBalance => 'Saldo inicial',
            self::LegacyDisbursement => 'Pago de haber anterior',
            self::Reversal => 'Reversión',
            self::AuthorizedAdjustment => 'Ajuste autorizado',
        };
    }

    /** Los que aumentan lo que el organismo tiene en custodia. */
    public function isIncome(): bool
    {
        return in_array($this, [
            self::FundsReceived,
            self::OpeningBalance,
        ], true);
    }

    /** Los que lo disminuyen, porque el dinero salió hacia su dueño. */
    public function isDisbursement(): bool
    {
        return in_array($this, [
            self::CashDisbursement,
            self::BankDisbursement,
            self::LegacyDisbursement,
        ], true);
    }
}
