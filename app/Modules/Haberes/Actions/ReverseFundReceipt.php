<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Banking\Actions\ReleaseFundReceiptAllocations;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\InverseEntry;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Support\Reason;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deshace una recepción que no ocurrió: el dinero sale de los libros.
 *
 * El trigger de `fund_receipts` dice «una recepcion de fondos no se borra:
 * se revierte» y «los datos no se editan: se revierte y se registra de
 * nuevo». Esto es esa reversión.
 *
 * El caso típico: el crédito de nuestro propio depósito de
 * efectivo llega al extracto como cualquier otro. Si alguien lo registra
 * como recepción en vez de acreditarlo contra el traslado, la misma plata
 * queda contada dos veces —una en tránsito desde la caja y otra como si
 * hubiera entrado de la nada— y el traslado ya no se puede acreditar,
 * porque el movimiento se quedó sin saldo libre.
 *
 * **El asiento inverso se arma dando vuelta el original, línea por
 * línea.** No se escriben las cuentas a mano: una recepción bancaria
 * asienta contra `BANK_ACCOUNT` y una de mostrador contra `CASH_ON_HAND`,
 * y el día que aparezca una tercera vía esto seguiría siendo correcto sin
 * que nadie se acuerde de tocarlo. Lo que se deshace es exactamente lo que
 * se hizo.
 *
 * **Y devuelve el saldo libre al movimiento del extracto**, que es lo que
 * permite volver a imputarlo bien. Sin eso la reversión limpiaría los
 * libros y dejaría el crédito bancario trabado para siempre.
 *
 * No revierte lo que ya tiene dueño: para eso está desasignar, y el orden
 * importa —primero se saca la plata de las cuotas, después se deshace su
 * entrada—.
 */
final class ReverseFundReceipt
{
    public function __construct(
        private readonly PostJournalEntry $asentar,
        private readonly InstallmentFunding $financiacion,
        private readonly ReleaseFundReceiptAllocations $liberar,
        private readonly RecordAuditEvent $auditar,
        private readonly InverseEntry $inverso,
    ) {}

    /**
     * @param  Reason  $motivo  Obligatorio: los CHECK
     *                          `financial_events_reversal_reason_check` y
     *                          `fund_receipts_reversal_reason_code_check` atan
     *                          el motivo a toda reversión, y el contrato del
     *                          Action tiene que decir lo mismo que la base.
     *
     * @throws ValidationException
     */
    public function handle(
        FundReceipt $receipt,
        Reason $motivo,
        string $idempotencyKey,
        ?int $actorId = null,
    ): FundReceipt {
        $reason = $motivo->describe();

        return DB::transaction(function () use ($receipt, $reason, $motivo, $idempotencyKey, $actorId): FundReceipt {
            $bloqueada = FundReceipt::query()->lockForUpdate()->findOrFail($receipt->id);

            // Un segundo envío del mismo formulario.
            if ($bloqueada->reversal_event_id !== null) {
                return $bloqueada;
            }

            $this->assertSinDueño($bloqueada);

            $evento = $this->asentar->handle(
                type: FinancialEventType::Reversal,
                idempotencyKey: $idempotencyKey,
                lines: $this->inverso->of($bloqueada->financial_event_id),
                date: BusinessDate::today(),
                cashBoxId: $bloqueada->cash_box_id,
                description: $reason,
                actorId: $actorId,
                reversalOfId: $bloqueada->financial_event_id,
                reversalReason: $reason,
            );

            $this->liberar->handle($bloqueada, $evento->id, $reason, $actorId);

            $bloqueada->forceFill([
                'reversal_event_id' => $evento->id,
                'reversed_at' => now(),
                'reversed_by' => $actorId,
                'reversal_reason' => $reason,
                'reversal_reason_code' => $motivo->code->value,
            ])->save();

            $this->auditar->handle('recepcion.revertida', $bloqueada, null, null, [
                'amount' => $bloqueada->amount,
                'reversal_event_id' => $evento->id,
                ...$motivo->auditMetadata('motivo'),
            ], actorId: $actorId);

            return $bloqueada->refresh();
        });
    }

    /**
     * Nada de esta recepción puede estar financiando una cuota.
     *
     * Se rechaza en vez de desasignar por cuenta propia: sacarle el dinero
     * a una cuota es una decisión con su propio permiso y su propio
     * motivo, y encadenarla acá la dejaría ocurrir sin que nadie la pida.
     *
     * @throws ValidationException
     */
    private function assertSinDueño(FundReceipt $receipt): void
    {
        /*
         * Revertir una recepción del sistema anterior desharía el asiento
         * de la apertura o el del apartado, no un ingreso: no hay nada que
         * entró por error. Lo que se puede hacer es liberarla de su cuota.
         */
        if ($receipt->origin->isLegacy()) {
            throw ValidationException::withMessages([
                'receiptId' => 'Esa plata es del sistema anterior: no se revierte, se libera de la cuota.',
            ]);
        }

        $asignado = $this->financiacion->allocatedFrom($receipt);

        if (! Decimal::equals($asignado, '0')) {
            throw ValidationException::withMessages([
                'receiptId' => sprintf(
                    'Esta recepción ya financia cuotas por $ %s. Primero hay que desasignar esos fondos.',
                    Decimal::format($asignado),
                ),
            ]);
        }
    }
}
