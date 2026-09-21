<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Haberes\Enums\DisbursementStatus;
use App\Modules\Haberes\Models\Disbursement;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un egreso por transferencia que salió y todavía no está confirmado.
 *
 * Igual que su hermana del mostrador, **alimenta la pantalla y la planilla
 * impresa**: quien cruza el extracto con la hoja en la mano tiene que ver
 * los mismos renglones en el mismo orden que la pantalla desde la que la
 * imprimió.
 *
 * Los datos del beneficiario salen de la Orden de Pago cuando hay una, y no
 * de la persona: la Orden los congeló en el momento de emitirse y es lo que
 * el organismo tiene en sus manos. Si el domicilio cambió después, el papel
 * que hay que cotejar sigue diciendo el anterior.
 */
#[TypeScript]
final class UnconfirmedTransferRowData extends Data
{
    public function __construct(
        public int $disbursementId,
        public int $installmentId,
        public int $expedienteId,
        /** El ordinal dentro del expediente, que es lo que la ruta resuelve. */
        public int $haberNumber,
        public string $expedienteNumber,
        public string $beneficiaryName,
        public string $installmentLabel,
        /** @var numeric-string */
        public string $amount,
        public ?string $paymentOrderNumber,
        /** Cuándo avisó el organismo que transfirió (§2.3.1). */
        public ?string $reportedAt,
        /** Cuándo se reconoció el débito en el extracto (§2.3.3). */
        public ?string $debitObservedAt,
        public DisbursementStatus $status,
        /** Qué falta para poder validarlo, en una frase. */
        public ?string $missingStep,
        /** Días desde el primero de los dos hechos que ya ocurrieron. */
        public int $waitingDays,
    ) {}

    public static function fromModel(Disbursement $egreso, ?string $missingStep, int $waitingDays): self
    {
        $cuota = $egreso->installment;
        $haber = $cuota->haber;
        $orden = $egreso->paymentOrder;

        return new self(
            disbursementId: (int) $egreso->id,
            installmentId: (int) $cuota->id,
            expedienteId: (int) $haber->expediente_id,
            haberNumber: $haber->haber_number,
            expedienteNumber: $orden->expediente_number_snapshot ?? $haber->expediente->display_number,
            beneficiaryName: $orden->beneficiary_name_snapshot ?? $haber->beneficiary->name,
            installmentLabel: sprintf('Haber %d · Cuota %d', $haber->haber_number, $cuota->installment_number),
            amount: $egreso->amount,
            paymentOrderNumber: $orden?->formatted_number,
            reportedAt: $egreso->report_received_at?->format('Y-m-d'),
            debitObservedAt: $egreso->bank_debit_observed_at?->format('Y-m-d'),
            status: $egreso->status,
            missingStep: $missingStep,
            waitingDays: $waitingDays,
        );
    }
}
