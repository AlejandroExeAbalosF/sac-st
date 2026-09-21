<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Haberes\Enums\DisbursementMethod;
use App\Modules\Haberes\Enums\DisbursementStatus;
use App\Modules\Haberes\Enums\PaymentChannel;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Shared\Models\Receipt;

/**
 * El estado completo de una cuota frente a su egreso.
 *
 * Hermano de `PaymentOrderReadiness` y con el mismo propósito: una sola
 * respuesta para las preguntas que la tarjeta, los diálogos y los Actions
 * se hacen por separado y tienen que contestar igual.
 *
 * ── Por qué son tantos permisos y no uno ───────────────────────────────
 *
 * Porque el egreso bancario **no es un acto sino tres** (§2.3): el
 * organismo informa, el débito aparece en el extracto, y el contador
 * coteja los dos contra la Orden. Cada uno tiene su botón, su momento y su
 * permiso, y el de mostrador no tiene ninguno de los tres —ahí el
 * trabajador está enfrente y se le paga—.
 *
 * Cada `can*` de acá abajo corresponde exactamente a un Action, y sale del
 * servidor por la misma razón de siempre: que la pantalla ofrezca algo que
 * el Action después rechaza es el error que este objeto existe para
 * evitar.
 */
final readonly class DisbursementReadiness
{
    /**
     * @param  numeric-string  $amount
     * @param  list<Receipt>  $voidedExpenseReceipts
     */
    public function __construct(
        public PaymentChannel $channel,
        /**
         * Si el egreso se opera desde acá.
         *
         * Falso solo mientras el depósito no acreditó: ahí el dinero no
         * está ni en la caja ni en la cuenta del organismo, y no hay nada
         * que entregar ni que pedir (§2.4.7).
         */
        public bool $applies,
        /** Lo que impide avanzar hoy, en una frase para la pantalla. */
        public ?string $blockedReason,
        /** Por dónde va a salir: efectivo, el cheque mismo, o transferencia. */
        public ?DisbursementMethod $method,
        /** Lo que le corresponde cobrar, ya financiado. */
        public string $amount,
        /** El egreso en curso o confirmado, si ya empezó. */
        public ?Disbursement $disbursement,
        /** El comprobante que el beneficiario firmó. */
        public ?Receipt $expenseReceipt,
        /** El recibo de ingreso, que va antes que éste. */
        public ?Receipt $incomeReceipt,
        /** La Orden que autoriza el pago, en el circuito bancario. */
        public ?PaymentOrder $paymentOrder,
        /** Qué le falta al egreso bancario para poder validarse. */
        public ?string $pendingStep,
        public array $voidedExpenseReceipts = [],
    ) {}

    /** Si se paga en el mostrador, sin pedirle nada al organismo. */
    public function isCounter(): bool
    {
        return $this->channel === PaymentChannel::Counter;
    }

    /** Si se puede entregar el dinero en mano ahora mismo. */
    public function canPay(): bool
    {
        return $this->applies
            && $this->isCounter()
            && $this->blockedReason === null
            && $this->disbursement === null
            && $this->method !== null;
    }

    /** Si se puede cargar el aviso del organismo (§2.3.1). */
    public function canReportTransfer(): bool
    {
        return $this->transferAbierta()
            && $this->disbursement?->report_received_at === null;
    }

    /** Si se puede reconocer el débito del extracto (§2.3.2). */
    public function canLinkDebit(): bool
    {
        return $this->transferAbierta()
            && $this->disbursement?->bank_transaction_id === null;
    }

    /** Si el débito reconocido todavía se puede corregir. */
    public function canUnlinkDebit(): bool
    {
        return $this->transferAbierta()
            && $this->disbursement?->bank_transaction_id !== null;
    }

    /** Si el contador puede cotejar y dar el pago por hecho (§2.3.4). */
    public function canValidate(): bool
    {
        return $this->transferAbierta()
            && $this->disbursement?->status === DisbursementStatus::ReadyForValidation;
    }

    /**
     * Si falta el papel de un pago que ya ocurrió.
     *
     * En el mostrador el acto entrega y emite de una vez, así que este
     * hueco aparece solo si el recibo se anuló. En la transferencia es el
     * estado normal apenas se valida: el pago está confirmado y el recibo
     * es el paso siguiente.
     */
    public function needsReceipt(): bool
    {
        return $this->disbursement?->status === DisbursementStatus::Confirmed
            && $this->expenseReceipt === null;
    }

    /**
     * Si el circuito bancario está abierto para operar.
     *
     * **Con el egreso todavía sin existir también está abierto**, y ése es
     * el caso normal: el egreso no se crea al emitir la Orden sino con el
     * primero de los dos hechos que lleguen —el informe o el débito—,
     * porque hasta entonces no hay nada que registrar. La Orden es un
     * pedido, no un pago.
     *
     * Se cierra al confirmar: ahí el §2.3 ya se cumplió entero y lo que
     * sigue es el recibo.
     */
    private function transferAbierta(): bool
    {
        return $this->applies
            && ! $this->isCounter()
            && $this->blockedReason === null
            && $this->disbursement?->status !== DisbursementStatus::Confirmed;
    }
}
