<?php

declare(strict_types=1);

namespace App\Modules\Shared\Enums;

/**
 * A qué se adjunta un archivo.
 *
 * El valor guardado es el nombre corto, no la clase: mover `Expediente` de
 * módulo no puede invalidar la evidencia que apunta a él. Es el mismo
 * criterio que usa `audit_events` con `class_basename`.
 */
enum AttachmentSubject: string
{
    case Expediente = 'expediente';

    /** Una importación de extracto: el archivo que el banco entregó. */
    case Import = 'import';

    case Receipt = 'receipt';
    case PaymentOrder = 'payment_order';
    case Pase = 'pase';
    case Disbursement = 'disbursement';
    case CashTransfer = 'cash_transfer';

    /** El ticket del depósito que llega con el expediente. */
    case DepositTicket = 'deposit_ticket';

    /** La planilla del día: el Excel que el área archiva y firma. */
    case PeriodClosing = 'period_closing';

    public function label(): string
    {
        return match ($this) {
            self::Expediente => 'Expediente',
            self::Import => 'Extracto importado',
            self::Receipt => 'Recibo',
            self::PaymentOrder => 'Orden de Pago',
            self::Pase => 'Pase',
            self::Disbursement => 'Egreso',
            self::CashTransfer => 'Traslado de efectivo',
            self::DepositTicket => 'Ticket de depósito',
            self::PeriodClosing => 'Planilla de caja',
        };
    }
}
