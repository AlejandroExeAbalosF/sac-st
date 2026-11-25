<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Enums\PaseStatus;
use App\Modules\Haberes\Enums\PaymentOrderStatus;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Support\Reason;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Anula una Orden de Pago y, con ella, su Pase.
 *
 * **Es la única salida de un dato mal impreso.** La Orden es append-only:
 * el importe, el beneficiario, la cuenta y el número no se editan, porque
 * el papel ya está en manos del organismo y corregir el registro por
 * detrás lo dejaría diciendo algo distinto del documento que circula. Sin
 * esta operación, un dígito mal tipeado quedaría congelado para siempre.
 *
 * **Los dos documentos caen juntos.** El §9.7 lo dice: *«si el error está
 * en la Orden misma, se rechaza o anula junto con su Pase, y se emiten una
 * Orden y un Pase nuevos, encadenados por `replaces_order_id`»*. Un Pase
 * vivo apuntando a una Orden anulada sería una nota pidiendo transferir
 * contra un documento que ya no vale.
 *
 * **El número no se recicla.** La anulada se queda con el suyo —un
 * documento que existió lo consumió— y la reemplazante toma el siguiente
 * libre de la serie, que puede no ser el que seguía si otra cuota emitió
 * en el medio. Es como funciona un talonario.
 *
 * Lo que esto **no** hace es tocar el recibo de ingreso ni las
 * asignaciones: el dinero entró y sigue entrado. Anular la Orden deshace
 * el pedido de pago, no el ingreso que lo respalda.
 */
final class VoidPaymentOrder
{
    public function __construct(
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(PaymentOrder $order, Reason $motivo, ?int $actorId = null): PaymentOrder
    {
        /*
         * Quién anula no es opcional. La base lo exige —igual que en los
         * recibos— y el motivo es el mismo: un documento dado de baja sin
         * responsable deja el rastro incompleto justo donde más importa.
         * El mensaje sale de acá y no del `CHECK`, que no sabe hablar.
         */
        if ($actorId === null) {
            throw ValidationException::withMessages([
                'orderId' => 'Anular una Orden exige registrar quién lo hace.',
            ]);
        }

        return DB::transaction(function () use ($order, $motivo, $actorId): PaymentOrder {
            /*
             * Bajo lock y releída: una transferencia que se registra en el
             * medio bloquea la misma fila (`disbursements_order_active`), así
             * que una de las dos espera a la otra y ve lo que dejó.
             */
            $orden = PaymentOrder::query()->lockForUpdate()->findOrFail($order->id);

            $this->assertVoidable($orden);

            $orden->forceFill([
                'status' => PaymentOrderStatus::Voided,
                'voided_by' => $actorId,
                'voided_at' => now(),
                'rejection_or_void_reason_code' => $motivo->code,
                'rejection_or_void_reason' => $motivo->detail,
            ])->save();

            $orden->loadMissing('pase');
            $orden->pase?->forceFill(['status' => PaseStatus::Voided])->save();

            $this->auditar->handle('orden-de-pago.anulada', $orden, after: [
                'formatted_number' => $orden->formatted_number,
                'beneficiary_installment_id' => $orden->beneficiary_installment_id,
                'reason' => $motivo->describe(),
            ], metadata: ['motivo_codigo' => $motivo->code->value], actorId: $actorId);

            return $orden;
        });
    }

    /**
     * @throws ValidationException
     */
    private function assertVoidable(PaymentOrder $order): void
    {
        if ($order->status === PaymentOrderStatus::Voided) {
            throw ValidationException::withMessages([
                'orderId' => 'Esa Orden ya está anulada.',
            ]);
        }

        /*
         * Una Orden completada es una cuota pagada: el dinero salió del
         * organismo y hay un recibo de egreso emitido. Anularla no
         * devolvería nada, solo dejaría el registro sin el documento que
         * explica ese egreso.
         */
        if ($order->status === PaymentOrderStatus::Completed) {
            throw ValidationException::withMessages([
                'orderId' => 'Esa Orden está completada: el egreso ya se validó. '
                    .'Lo que corresponde es revertir el egreso, no anular la Orden.',
            ]);
        }

        /*
         * Con la transferencia en curso, el egreso quedaría atado a una Orden
         * anulada y la que la reemplace no tendría dónde registrar su pago.
         * Primero se cierra la transferencia, con su motivo; recién después
         * cae la Orden. La base lo impide igual (`payment_orders_status_guard`).
         */
        $egreso = Disbursement::query()->live()->where('payment_order_id', $order->id)->first();

        if ($egreso === null) {
            return;
        }

        // Con el débito reconocido no se da por fallida (`FailTransferDisbursement`),
        // y sin el informe tampoco se valida (`TransferStage::missing`).
        if ($egreso->bank_transaction_id !== null) {
            $paso = $egreso->report_received_at === null
                ? 'registrar el informe del organismo y validar el pago'
                : 'validar el pago';

            throw ValidationException::withMessages([
                'orderId' => 'La Orden tiene una transferencia con el débito ya vinculado. '
                    ."Si corresponde a esta Orden, hay que {$paso}; si se vinculó por error, "
                    .'desvincularlo y darla por fallida antes de anular.',
            ]);
        }

        throw ValidationException::withMessages([
            'orderId' => 'La Orden tiene una transferencia en curso: hay que darla por fallida antes de anular.',
        ]);
    }
}
