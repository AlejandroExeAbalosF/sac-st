<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Enums\PaseStatus;
use App\Modules\Haberes\Enums\PaymentOrderStatus;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Shared\Actions\RecordAuditEvent;
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
    public function handle(PaymentOrder $order, string $reason, ?int $actorId = null): PaymentOrder
    {
        $motivo = trim($reason);

        if ($motivo === '') {
            throw ValidationException::withMessages([
                'reason' => 'Hay que decir por qué se anula la Orden.',
            ]);
        }

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

        $this->assertVoidable($order);

        DB::transaction(function () use ($order, $motivo, $actorId): void {
            $order->forceFill([
                'status' => PaymentOrderStatus::Voided,
                'voided_by' => $actorId,
                'voided_at' => now(),
                'rejection_or_void_reason' => $motivo,
            ])->save();

            $order->loadMissing('pase');
            $order->pase?->forceFill(['status' => PaseStatus::Voided])->save();
        });

        $this->auditar->handle('orden-de-pago.anulada', $order, after: [
            'formatted_number' => $order->formatted_number,
            'beneficiary_installment_id' => $order->beneficiary_installment_id,
            'reason' => $motivo,
        ], actorId: $actorId);

        return $order;
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
    }
}
