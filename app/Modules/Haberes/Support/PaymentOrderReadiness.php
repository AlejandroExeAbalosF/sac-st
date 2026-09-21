<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Haberes\Enums\PaymentChannel;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Shared\Models\Receipt;

/**
 * El estado completo de una cuota frente a su Orden de Pago.
 *
 * Una sola respuesta para las tres preguntas que la pantalla y el Action
 * se hacen por separado y que tienen que contestar igual:
 *
 * - **¿corresponde una Orden?** (`applies`)
 * - **¿se puede emitir hoy?** (`blockedReason`, `missing`)
 * - **¿con qué datos?** (`rows`, `organismBankAccountId`, `incomeReceipt`)
 *
 * Que la pantalla ofrezca un botón que el Action después rechaza es el
 * error que este objeto existe para evitar. Los dos leen esto.
 */
final readonly class PaymentOrderReadiness
{
    /**
     * @param  list<MissingOrderField>  $missing
     * @param  list<FundingSourceRow>  $rows
     */
    public function __construct(
        public PaymentChannel $channel,
        /**
         * Si el circuito de esta cuota termina en una Orden.
         *
         * Falso para el mostrador, y no es una traba: es que el pago en
         * mano no le pide nada al organismo superior. Si el efectivo se
         * deposita después, esto pasa a verdadero solo.
         */
        public bool $applies,
        /** Lo que impide emitirla hoy, en una frase para la pantalla. */
        public ?string $blockedReason,
        public array $missing,
        /** La Orden vigente, si ya se emitió. */
        public ?PaymentOrder $activeOrder,
        /** El recibo de ingreso que la Orden va a referenciar. */
        public ?Receipt $incomeReceipt,
        public array $rows,
        public ?int $organismBankAccountId,
    ) {}

    /**
     * Si se puede emitir ahora mismo.
     *
     * Los datos sugeridos que falten no cuentan: el formulario tolera
     * renglones en blanco —la Orden 3582 sale con el teléfono de la
     * empresa vacío— y frenar por eso sería inventar una exigencia que el
     * papel no tiene.
     */
    public function canIssue(): bool
    {
        return $this->applies
            && $this->blockedReason === null
            && $this->activeOrder === null
            && $this->requiredMissing() === [];
    }

    /**
     * Lo que falta y sí frena.
     *
     * @return list<MissingOrderField>
     */
    public function requiredMissing(): array
    {
        return array_values(array_filter(
            $this->missing,
            fn (MissingOrderField $campo): bool => $campo->required,
        ));
    }
}
