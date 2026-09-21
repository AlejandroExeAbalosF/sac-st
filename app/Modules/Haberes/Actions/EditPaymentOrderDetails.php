<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Haberes\Support\PaymentOrderObservation;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrige lo accesorio de una Orden y de su Pase.
 *
 * **Es uno de los dos caminos, y lo elige quien opera.** Cuando falta o
 * está mal un dato accesorio se agrega una foja aclarando el problema y
 * los mismos documentos siguen su camino; cuando el organismo devuelve la
 * Orden, en cambio, se anula y se emite otra (`VoidPaymentOrder`). El área
 * confirmó que las dos situaciones existen y que la decisión es del
 * operador, que es el único que tiene el papel devuelto a la vista.
 *
 * Lo que este Action resuelve es el primer caso: arreglar la foja donde
 * figura el CBU o el destinatario de la nota, que son datos del documento
 * y no del dinero.
 *
 * ── Qué se puede tocar, y por qué justo eso ────────────────────────────
 *
 * | Se edita | No se edita |
 * |---|---|
 * | la foja del CBU —y con ella el `OBS`, que se redacta solo| importe, beneficiario, cuenta |
 * | destinatario y notas del Pase | número, fecha, tipo de Orden |
 * | | el recibo de ingreso que referencia |
 *
 * La columna derecha es **la misma que protege el trigger append-only** de
 * `payment_orders`, y no por casualidad: es lo que compromete al organismo
 * a mover dinero. Si eso está mal, no hay corrección que valga —hay que
 * anular y rehacer—. La columna izquierda es texto administrativo: aclara,
 * no compromete.
 *
 * Cada cambio deja su antes y su después en `audit_events`. Un documento
 * que ya salió del área y cuya observación cambió tiene que poder
 * explicarse.
 */
final class EditPaymentOrderDetails
{
    public function __construct(
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @param  array{
     *     cbuFolio?: string|null,
     *     paseDestination?: string|null,
     *     paseNotes?: string|null,
     * }  $datos
     *
     * @throws ValidationException
     */
    public function handle(PaymentOrder $order, array $datos, ?int $actorId = null): PaymentOrder
    {
        $this->assertEditable($order);

        $order->loadMissing('pase');

        $antesOrden = [
            'notes' => $order->notes,
            'cbu_folio_snapshot' => $order->cbu_folio_snapshot,
        ];

        /*
         * El OBS se recompone desde la foja y no se recibe: es el mismo
         * dato (D-003). Si se dejara editar aparte, corregir la foja
         * dejaría el renglón impreso citando la anterior.
         */
        $foja = $this->limpiar($datos['cbuFolio'] ?? null);

        $despuesOrden = [
            'notes' => PaymentOrderObservation::forFolio($foja),
            'cbu_folio_snapshot' => $foja,
        ];

        $pase = $order->pase;

        $antesPase = $pase === null ? [] : [
            'destination' => $pase->destination,
            'notes' => $pase->notes,
        ];

        $despuesPase = $pase === null ? [] : [
            /*
             * El destinatario no se vacía: una nota sin destinatario no se
             * puede imprimir. Si no llega nada, queda el que tenía.
             */
            'destination' => $this->limpiar($datos['paseDestination'] ?? null) ?? $pase->destination,
            'notes' => $this->limpiar($datos['paseNotes'] ?? null),
        ];

        DB::transaction(function () use ($order, $despuesOrden, $pase, $despuesPase): void {
            $order->forceFill($despuesOrden)->save();

            $pase?->forceFill($despuesPase)->save();
        });

        [$viejos, $nuevos] = RecordAuditEvent::diff(
            [...$antesOrden, ...$this->conPrefijo($antesPase)],
            [...$despuesOrden, ...$this->conPrefijo($despuesPase)],
        );

        if ($viejos !== [] || $nuevos !== []) {
            $this->auditar->handle('orden-de-pago.detalles-corregidos', $order, $viejos, $nuevos, [
                'formatted_number' => $order->formatted_number,
                'beneficiary_installment_id' => $order->beneficiary_installment_id,
            ], actorId: $actorId);
        }

        return $order->refresh();
    }

    /**
     * Distingue en el rastro lo de la nota de lo de la Orden.
     *
     * Las dos tienen un campo `notes`, y sin el prefijo el evento diría que
     * cambió «notes» sin decir de cuál de los dos papeles.
     *
     * @param  array<string, mixed>  $valores
     * @return array<string, mixed>
     */
    private function conPrefijo(array $valores): array
    {
        $prefijadas = [];

        foreach ($valores as $campo => $valor) {
            $prefijadas['pase_'.$campo] = $valor;
        }

        return $prefijadas;
    }

    private function limpiar(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $limpio = trim($valor);

        return $limpio === '' ? null : $limpio;
    }

    /**
     * @throws ValidationException
     */
    private function assertEditable(PaymentOrder $order): void
    {
        if (! $order->status->isActive()) {
            throw ValidationException::withMessages([
                'orderId' => sprintf(
                    'La Orden está %s: ya no está en circulación y su texto queda como quedó.',
                    mb_strtolower($order->status->label()),
                ),
            ]);
        }
    }
}
