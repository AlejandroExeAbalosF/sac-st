<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Haberes\Enums\DisbursementMethod;
use App\Modules\Haberes\Enums\InstallmentStage;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Support\Money\Decimal;
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
        public ?string $paymentMethod = null,
        public string $stageCode = '',
        public ?string $paymentMethodCode = null,
    ) {}

    /** @param numeric-string $financiado */
    public static function fromModel(
        BeneficiaryInstallment $cuota,
        InstallmentStage $etapa,
        string $financiado,
        ?PaymentMedium $medioDeIngreso,
        ?DisbursementMethod $medioDeEgreso,
        bool $tieneReciboDeEgreso,
    ): self {
        $haber = $cuota->haber;
        $pagada = $etapa === InstallmentStage::Paid
            || $cuota->workflow_status === InstallmentWorkflowStatus::Paid;
        $status = match (true) {
            $pagada => $tieneReciboDeEgreso
                ? 'Pago confirmado'
                : 'Pago confirmado · falta recibo de egreso',
            $cuota->workflow_status !== InstallmentWorkflowStatus::Active => $cuota->workflow_status->label(),
            default => self::estadoActivo($cuota, $etapa, $financiado),
        };
        $retenida = (bool) $cuota->managementLabel?->blocks_payment
            && $cuota->workflow_status === InstallmentWorkflowStatus::Active
            && ! $pagada;
        $metodo = match (true) {
            $pagada => $medioDeEgreso,
            $cuota->workflow_status !== InstallmentWorkflowStatus::Active => null,
            $etapa === InstallmentStage::InCashBox || $etapa === InstallmentStage::AwaitingReceipt => match ($medioDeIngreso) {
                PaymentMedium::Cash => DisbursementMethod::Cash,
                PaymentMedium::Cheque => DisbursementMethod::Cheque,
                PaymentMedium::Bank => DisbursementMethod::BankTransfer,
                default => null,
            },
            in_array($etapa, [
                InstallmentStage::DepositInTransit,
                InstallmentStage::AtBank,
                InstallmentStage::OrderIssued,
                InstallmentStage::TransferReported,
                InstallmentStage::DebitObserved,
                InstallmentStage::ReadyToValidate,
            ], true) => DisbursementMethod::BankTransfer,
            default => null,
        };
        $stageCode = match (true) {
            $cuota->workflow_status === InstallmentWorkflowStatus::LegacySettled => InstallmentStage::PaidBeforeOpening->value,
            $cuota->workflow_status === InstallmentWorkflowStatus::Cancelled => InstallmentStage::Cancelled->value,
            $cuota->workflow_status === InstallmentWorkflowStatus::Blocked => InstallmentStage::Blocked->value,
            $cuota->workflow_status === InstallmentWorkflowStatus::Suspended => InstallmentStage::Suspended->value,
            $pagada && ! $tieneReciboDeEgreso => 'paid_missing_receipt',
            $etapa === InstallmentStage::Unfunded && ! Decimal::equals($financiado, '0') => 'partially_funded',
            default => $etapa->value,
        };

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
            paymentMethod: $metodo?->label(),
            stageCode: $stageCode,
            paymentMethodCode: $metodo?->value,
        );
    }

    /** @param numeric-string $financiado */
    private static function estadoActivo(
        BeneficiaryInstallment $cuota,
        InstallmentStage $etapa,
        string $financiado,
    ): string {
        return match ($etapa) {
            InstallmentStage::Unfunded => Decimal::equals($financiado, '0')
                ? 'Sin fondos imputados'
                : 'Fondos parciales · falta $ '.Decimal::format(Decimal::sub($cuota->importeEsperado(), $financiado)),
            InstallmentStage::AwaitingReceipt => 'Fondos recibidos · emitir recibo de ingreso',
            InstallmentStage::InCashBox => 'Entrega pendiente',
            InstallmentStage::DepositInTransit => 'Depósito en tránsito · falta acreditación',
            InstallmentStage::AtBank => 'Fondos acreditados · falta Orden de Pago',
            InstallmentStage::OrderIssued => 'Orden emitida · faltan informe y débito',
            InstallmentStage::TransferReported => 'Transferencia informada · falta débito',
            InstallmentStage::DebitObserved => 'Débito identificado · falta informe',
            InstallmentStage::ReadyToValidate => 'Informe y débito registrados · falta validación',
            default => $etapa->label(),
        };
    }
}
