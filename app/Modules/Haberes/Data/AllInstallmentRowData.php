<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Haberes\Enums\InstallmentStage;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Una cuota del inventario completo, con su situación actual. */
#[TypeScript]
final class AllInstallmentRowData extends Data
{
    public function __construct(
        public int $installmentId,
        public int $expedienteId,
        public int $haberNumber,
        public string $expedienteNumber,
        public string $beneficiaryName,
        public string $installmentLabel,
        public ?string $concept,
        /** @var numeric-string */
        public string $amount,
        public string $status,
    ) {}

    public static function fromModel(BeneficiaryInstallment $cuota, InstallmentStage $etapa): self
    {
        $haber = $cuota->haber;
        $status = $cuota->workflow_status === InstallmentWorkflowStatus::Active
            ? $etapa->label()
            : $cuota->workflow_status->label();
        $retenida = (bool) $cuota->managementLabel?->blocks_payment
            && $cuota->workflow_status === InstallmentWorkflowStatus::Active;

        return new self(
            installmentId: (int) $cuota->id,
            expedienteId: (int) $haber->expediente_id,
            haberNumber: $haber->haber_number,
            expedienteNumber: $haber->expediente->display_number,
            beneficiaryName: $haber->beneficiary->name,
            installmentLabel: sprintf('Haber %d · Cuota %d%s',
                $haber->haber_number,
                $cuota->installment_number,
                $haber->expected_installment_count === null ? '' : ' de '.$haber->expected_installment_count,
            ),
            concept: $cuota->description ?? $haber->concept,
            amount: $cuota->importeEsperado(),
            status: $status.($retenida ? ' · pago retenido' : ''),
        );
    }
}
