<?php

declare(strict_types=1);

namespace App\Modules\Shared\Enums;

/** Los tres documentos numerados del sistema — §9.8 del DER. */
enum DocumentType: string
{
    case ReceiptIncome = 'receipt_income';
    case ReceiptExpense = 'receipt_expense';
    case PaymentOrder = 'payment_order';

    public function label(): string
    {
        return match ($this) {
            self::ReceiptIncome => 'Recibo de ingreso',
            self::ReceiptExpense => 'Recibo de egreso',
            self::PaymentOrder => 'Orden de Pago',
        };
    }

    /**
     * La serie de la que toma su número — §9.8 del DER.
     *
     * `ReceiptType` tiene su propia copia de este mapa para los dos
     * recibos, y se queda donde está: quien emite un recibo razona en
     * términos de tipo de recibo, no de tipo de documento. Acá está el
     * mapa completo, que es el que necesita quien numera algo que no es
     * un recibo.
     */
    public function seriesCode(): string
    {
        return match ($this) {
            self::ReceiptIncome => '0010',
            self::ReceiptExpense => '0020',
            self::PaymentOrder => '0030',
        };
    }
}
