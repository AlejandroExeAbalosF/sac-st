<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Shared\Models\Receipt;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Lo que el panel lateral necesita para explicar un comprobante.
 *
 * Nace de una pregunta concreta: en el libro del día la fila dice
 * «0010/00000003 · $ 240.000» y no dice de quién es. Para averiguarlo
 * había que salir de la caja, que es justo lo que no se quiere hacer
 * mientras se concilia.
 *
 * Trae las dos caras: lo que el papel dice —congelado el día de la
 * emisión— y a qué apunta hoy en el circuito. El segundo puede faltar y
 * eso no es un hueco: los pagos de haberes anteriores no cuelgan de
 * ninguna cuota del sistema, y su número de expediente es la referencia
 * al registro manual.
 */
#[TypeScript]
final class ReceiptPanelData extends Data
{
    public function __construct(
        public InstallmentReceiptData $receipt,
        /** `cash`, `cheque` o `bank`, como se emitió. */
        public string $medium,
        /** El concepto impreso. */
        public ?string $concept,
        /** «Recibí de» en el ingreso; a quién se le pagó en el egreso. */
        public ?string $counterpartyName,
        /** El nombre del beneficiario tal como salió impreso. */
        public ?string $beneficiaryNameOnPaper,
        /** El número de expediente tal como salió impreso. */
        public ?string $expedienteNumberOnPaper,
        /**
         * A qué apunta hoy. Ausente en los pagos de haberes anteriores,
         * donde lo impreso es una referencia y no un expediente del
         * sistema.
         */
        public ?ReceiptSubjectData $subject,
    ) {}

    public static function fromModel(Receipt $receipt, ?ReceiptSubjectData $subject): self
    {
        return new self(
            receipt: InstallmentReceiptData::fromModel($receipt),
            medium: $receipt->medium_snapshot,
            concept: $receipt->concept_snapshot,
            counterpartyName: $receipt->counterparty_name_snapshot,
            beneficiaryNameOnPaper: $receipt->beneficiary_name_snapshot,
            expedienteNumberOnPaper: $receipt->expediente_number_snapshot,
            subject: $subject,
        );
    }
}
