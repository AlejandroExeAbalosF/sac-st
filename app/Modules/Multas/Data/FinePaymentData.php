<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use App\Models\User;
use App\Modules\Multas\Enums\AppealKind;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Enums\FineTimeliness;
use App\Modules\Multas\Enums\PresentationPrecision;
use App\Modules\Multas\Models\FinePayment;
use App\Support\Money\Decimal;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un pago de la multa, como lo muestra la ficha.
 *
 * Lo aplicado y el excedente vienen de la aplicación: el importe es la
 * recepción entera (M05).
 */
#[TypeScript]
final class FinePaymentData extends Data
{
    public function __construct(
        public int $id,
        public FinePaymentMedium $medium,
        public FinePaymentStatus $status,
        /** @var numeric-string */
        public string $amount,
        /** @var numeric-string|null */
        public ?string $appliedAmount,
        /** @var numeric-string|null */
        public ?string $excessAmount,
        public ?string $presentedAt,
        public PresentationPrecision $presentedPrecision,
        public ?string $presentationEvidence,
        public ?FineTimeliness $timeliness,
        public ?string $judicialReference,
        public ?string $chequeNumber,
        public ?string $chequeBank,
        public ?int $receiptId,
        public ?string $receiptNumber,
        public ?string $talonarioNumber,
        public ?string $receiptDate,
        public bool $isCancellation,
        public ?string $createdByName,
        public ?string $createdAt,
        public ?string $declaredPaidOn,
        /**
         * Recurso del 20 %: tipo, base congelada y el 20 % calculado como
         * referencia. El importe del pago es el del recibo externo.
         */
        public ?AppealKind $appealKind,
        /** @var numeric-string|null */
        public ?string $appealBaseAmount,
        /** @var numeric-string|null */
        public ?string $appealRequiredAmount,
        public ?string $externalReceiptReference,
        /** El importe del recibo externo no es el 20 % calculado. */
        public bool $appealDiffers,
        public ?string $discardReason,
        /** El informado que este corrige. */
        public ?int $replacesPaymentId,
        /** @var list<FineAttachmentData> */
        public array $attachments,
    ) {}

    /**
     * Espera cargados `application.fundReceipt`, `paymentReceipt.receipt` y `creator`.
     *
     * @param  list<FineAttachmentData>  $adjuntos
     */
    public static function fromModel(FinePayment $pago, array $adjuntos = []): self
    {
        $aplicacion = $pago->application;
        $recepcion = $aplicacion?->fundReceipt;
        $recibo = $pago->paymentReceipt?->receipt;
        $autor = $pago->creator;

        return new self(
            id: $pago->id,
            medium: $pago->medium,
            status: $pago->status,
            amount: $pago->amount,
            appliedAmount: $aplicacion?->applied_amount,
            excessAmount: $aplicacion?->excess_amount,
            presentedAt: $pago->presented_at?->toIso8601String(),
            presentedPrecision: $pago->presented_precision,
            presentationEvidence: $pago->presentation_evidence,
            timeliness: $aplicacion?->timeliness,
            judicialReference: $pago->judicial_reference,
            chequeNumber: $recepcion?->cheque_number,
            chequeBank: $recepcion?->cheque_bank,
            receiptId: $recibo?->id,
            receiptNumber: $recibo?->formatted_number,
            talonarioNumber: $recibo?->talonario_number,
            receiptDate: $recibo?->issue_date->toDateString(),
            isCancellation: $pago->paymentReceipt !== null && $pago->paymentReceipt->is_cancellation,
            createdByName: $autor instanceof User ? $autor->name : null,
            createdAt: $pago->created_at?->toIso8601String(),
            declaredPaidOn: $pago->declared_paid_on?->toDateString(),
            appealKind: $pago->appeal_kind,
            appealBaseAmount: $pago->appeal_base_amount,
            appealRequiredAmount: $pago->appeal_required_amount,
            externalReceiptReference: $pago->external_receipt_reference,
            appealDiffers: $pago->appeal_required_amount !== null && ! Decimal::equals($pago->amount, $pago->appeal_required_amount),
            discardReason: $pago->discard_reason,
            replacesPaymentId: $pago->replaces_fine_payment_id,
            attachments: $adjuntos,
        );
    }
}
