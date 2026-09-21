<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Haberes\Enums\DisbursementMethod;
use App\Modules\Haberes\Enums\PaymentChannel;
use App\Modules\Haberes\Support\DisbursementReadiness;
use App\Modules\Shared\Models\Receipt;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Qué le corresponde a esta cuota en materia de egreso.
 *
 * Es lo que decide qué muestra la sección «Egreso» de la tarjeta, y viaja
 * calculado desde el servidor por la misma razón que el estado de la
 * Orden: si el front dedujera por su cuenta que «efectivo sin traslado se
 * paga en mano», tarde o temprano diría algo distinto de lo que el Action
 * acepta. La regla vive en `DisbursementEligibility` y de ahí sale esta
 * foto.
 *
 * **Los cinco `can*` no son un permiso repetido cinco veces**: son los
 * cinco actos distintos del circuito —entregar, informar, reconocer el
 * débito, validar y emitir el papel—, cada uno con su momento. El egreso
 * bancario es tres actos porque el §2.3 lo parte en tres.
 */
#[TypeScript]
final class InstallmentDisbursementData extends Data
{
    /** @param  list<InstallmentReceiptData>  $voidedReceipts */
    public function __construct(
        /** Por dónde sale el dinero de esta cuota. */
        public PaymentChannel $channel,
        /** Si el egreso se opera desde acá. Falso con el depósito en tránsito. */
        public bool $applies,
        /** Si se paga en el mostrador, sin pedirle nada al organismo. */
        public bool $isCounter,
        /** Lo que impide avanzar hoy, en una frase. */
        public ?string $blockedReason,
        /** Con qué se va a pagar. */
        public ?DisbursementMethod $method,
        /** @var numeric-string */
        public string $amount,
        public ?DisbursementSummaryData $disbursement,
        /** El comprobante que el beneficiario firmó. */
        public ?InstallmentReceiptData $receipt,
        public array $voidedReceipts,
        /** Si el recibo de ingreso —que va antes que éste— ya se emitió. */
        public bool $hasIncomeReceipt,
        /** La Orden que autoriza el pago, en el circuito bancario. */
        public ?string $orderNumber,
        /** Qué le falta al egreso bancario para poder validarse. */
        public ?string $pendingStep,

        /* ─── Los cinco actos ────────────────────────────────────────── */
        /** Entregar en mano y emitir el recibo, todo junto. */
        public bool $canPay,
        /** Cargar el aviso del organismo. */
        public bool $canReportTransfer,
        /** Reconocer el débito en el extracto. */
        public bool $canLinkDebit,
        /** Corregir el débito reconocido, mientras no esté validado. */
        public bool $canUnlinkDebit,
        /** Cotejar los tres papeles y dar el pago por hecho. */
        public bool $canValidate,
        /**
         * Emitir el papel de un pago ya confirmado.
         *
         * En la transferencia es el paso normal apenas se valida; en el
         * mostrador aparece solo si el recibo se anuló.
         */
        public bool $needsReceipt,
    ) {}

    public static function fromReadiness(DisbursementReadiness $estado): self
    {
        return new self(
            channel: $estado->channel,
            applies: $estado->applies,
            isCounter: $estado->isCounter(),
            blockedReason: $estado->blockedReason,
            method: $estado->method,
            amount: $estado->amount,
            disbursement: $estado->disbursement === null
                ? null
                : DisbursementSummaryData::fromModel($estado->disbursement),
            receipt: $estado->expenseReceipt === null
                ? null
                : InstallmentReceiptData::fromModel($estado->expenseReceipt),
            voidedReceipts: array_map(
                fn (Receipt $recibo): InstallmentReceiptData => InstallmentReceiptData::fromModel($recibo),
                $estado->voidedExpenseReceipts,
            ),
            hasIncomeReceipt: $estado->incomeReceipt !== null,
            orderNumber: $estado->paymentOrder?->formatted_number,
            pendingStep: $estado->pendingStep,
            canPay: $estado->canPay(),
            canReportTransfer: $estado->canReportTransfer(),
            canLinkDebit: $estado->canLinkDebit(),
            canUnlinkDebit: $estado->canUnlinkDebit(),
            canValidate: $estado->canValidate(),
            needsReceipt: $estado->needsReceipt(),
        );
    }
}
