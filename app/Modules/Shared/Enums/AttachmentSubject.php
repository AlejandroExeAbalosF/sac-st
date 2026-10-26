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

    /** Un papel del sistema anterior: recibo u Orden de Pago de talonario. */
    case LegacyDocument = 'legacy_document';

    /** Un papel del expediente de Multas sin documento propio. */
    case FineCase = 'fine_case';

    /** La resolución o el acta misma: su foto o su PDF. */
    case FineDocument = 'fine_document';

    /** Lo que respalda una multa en particular. */
    case Fine = 'fine';

    /** El comprobante de un pago anterior al sistema. */
    case FineHistoricalMovement = 'fine_historical_movement';

    /** El comprobante de un pago de una multa: la transferencia, el depósito o el recibo externo del recurso. */
    case FinePayment = 'fine_payment';

    /** El respaldo de un cheque rechazado, el aviso a la empresa o la disposición del papel. */
    case ChequeRejection = 'cheque_rejection';

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
            self::LegacyDocument => 'Papel del sistema anterior',
            self::FineCase => 'Expediente de multa',
            self::FineDocument => 'Documento de multa',
            self::Fine => 'Multa',
            self::FineHistoricalMovement => 'Pago anterior de una multa',
            self::FinePayment => 'Pago de una multa',
            self::ChequeRejection => 'Cheque rechazado',
        };
    }
}
