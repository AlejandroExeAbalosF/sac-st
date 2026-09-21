<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Haberes\Enums\PaymentChannel;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Support\InstallmentEditLock;
use App\Modules\Haberes\Support\MissingOrderField;
use App\Modules\Haberes\Support\PaymentOrderReadiness;
use App\Support\Money\Decimal;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Qué le corresponde a esta cuota en materia de Orden de Pago.
 *
 * Es lo que decide qué muestra la tarjeta, y viaja calculado desde el
 * servidor por una razón concreta: si el front dedujera por su cuenta que
 * «efectivo sin traslado no lleva Orden», tarde o temprano diría algo
 * distinto de lo que el Action acepta. La regla vive en
 * `PaymentOrderEligibility` y de ahí sale esta foto.
 */
#[TypeScript]
final class InstallmentOrderStateData extends Data
{
    /**
     * @param  list<MissingOrderFieldData>  $missing
     * @param  list<OrderDepositRowData>  $deposits
     */
    public function __construct(
        public PaymentChannel $channel,
        /** Si el circuito de esta cuota termina en una Orden. */
        public bool $applies,
        /** Lo que impide emitirla hoy, en una frase. */
        public ?string $blockedReason,
        public bool $canIssue,
        public array $missing,
        public ?PaymentOrderSummaryData $order,
        /**
         * La tabla de depósitos que se va a imprimir.
         *
         * Va acá y no en el modal porque es de la cuota: sale de sus
         * asignaciones y no se comparte con las demás del plan.
         */
        public array $deposits,
        /** @var numeric-string */
        public string $depositsTotal,
        /** La cuenta del organismo que el papel va a marcar. */
        public ?string $organismAccountLabel,
        /** El número de recibo que la Orden puede imprimir, en sus dos formas. */
        public ?string $incomeReceiptSystemNumber,
        public ?string $incomeReceiptTalonarioNumber,
        /**
         * Cuál de los dos encabezó el recibo, para proponer el mismo acá.
         *
         * El papel del área imprime `72273`, un número de talonario. Que
         * la Orden proponga el mismo criterio con el que se emitió el
         * recibo evita que los dos documentos de la misma cuota se
         * refieran al comprobante de maneras distintas.
         */
        public bool $incomeReceiptPrintsTalonario,
        /**
         * Si la cuota quedó trabada porque su Orden salió del área.
         *
         * Con el Pase emitido el expediente está en circulación y hay
         * alguien afuera leyendo lo que este dato dice. Corregirla sigue
         * siendo posible, pero exige registrar antes el caso y el motivo.
         */
        public bool $editLocked = false,
        /** Si esa ventana ya está abierta, y con qué motivo. */
        public ?string $editUnlockReason = null,
    ) {}

    public static function fromReadiness(
        BeneficiaryInstallment $installment,
        PaymentOrderReadiness $estado,
        ?string $organismAccountLabel = null,
    ): self {
        $recibo = $estado->incomeReceipt;
        $traba = app(InstallmentEditLock::class)->locksWith($estado->activeOrder);

        $depositos = array_map(
            fn ($fila): OrderDepositRowData => OrderDepositRowData::fromRow($fila),
            $estado->rows,
        );

        $total = '0.00';

        foreach ($estado->rows as $fila) {
            $total = Decimal::add($total, $fila->amount);
        }

        return new self(
            channel: $estado->channel,
            applies: $estado->applies,
            blockedReason: $estado->blockedReason,
            canIssue: $estado->canIssue(),
            missing: array_map(
                fn (MissingOrderField $campo): MissingOrderFieldData => new MissingOrderFieldData(
                    code: $campo->code,
                    section: $campo->section,
                    label: $campo->label,
                    reason: $campo->reason,
                    required: $campo->required,
                ),
                $estado->missing,
            ),
            order: $estado->activeOrder === null
                ? null
                : PaymentOrderSummaryData::fromModel($estado->activeOrder),
            deposits: $depositos,
            depositsTotal: $total,
            organismAccountLabel: $organismAccountLabel,
            incomeReceiptSystemNumber: $recibo?->formatted_number,
            incomeReceiptTalonarioNumber: $recibo?->talonario_number,
            incomeReceiptPrintsTalonario: $recibo->prints_talonario_number ?? false,
            editLocked: $traba && $installment->edit_unlocked_at === null,
            editUnlockReason: $traba ? $installment->edit_unlock_reason : null,
        );
    }
}
