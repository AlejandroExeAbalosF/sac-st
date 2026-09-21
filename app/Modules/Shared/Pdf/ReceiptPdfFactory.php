<?php

declare(strict_types=1);

namespace App\Modules\Shared\Pdf;

use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\Receipt;

/**
 * Qué formulario le corresponde a este comprobante.
 *
 * El tipo ya está en la fila —`receipts.receipt_type`— así que preguntarle
 * al que imprime cuál plantilla quiere sería pedirle un dato que el
 * sistema tiene. Y sería además un dato que puede equivocarse: un recibo
 * de egreso impreso sobre el formulario de ingreso saldría con el orden de
 * los renglones cambiado y con el pie firmado por quien no corresponde.
 */
final class ReceiptPdfFactory
{
    public function __construct(
        private readonly IncomeReceiptPdf $ingreso,
        private readonly ExpenseReceiptPdf $egreso,
    ) {}

    public function for(Receipt $receipt): ReceiptDocument
    {
        return match ($receipt->receipt_type) {
            ReceiptType::Income => $this->ingreso,
            ReceiptType::Expense => $this->egreso,
        };
    }
}
