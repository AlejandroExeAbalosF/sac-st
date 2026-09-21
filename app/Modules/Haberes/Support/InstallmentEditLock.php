<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Haberes\Enums\PaseStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\PaymentOrder;

/**
 * Si la cuota se puede editar, y qué la traba.
 *
 * **Hasta el Pase, todo es corregible.** El área lo definió así y el
 * motivo es más fuerte que la conveniencia (Correcciones §33, desvío 5):
 * el recibo de ingreso *copia* lo que imprime al emitirse, así que
 * corregir la cuota después no puede desmentir al papel, porque el papel
 * no la lee. Trabar antes no protegía nada; solo frenaba trabajo.
 *
 * **Con el Pase cambia.** El expediente sale del área y el dato entra en
 * circulación: hay una Orden en manos del organismo que dice un importe,
 * un beneficiario y una cuenta. Editar la cuota por detrás dejaría al
 * sistema afirmando algo distinto de lo que está viajando, y esta vez sí
 * hay alguien afuera leyendo.
 *
 * La salida no es un permiso sino una **ventana justificada**: alguien
 * registra el caso y el porqué, edita, y al guardar se vuelve a bloquear.
 * Ver `UnlockInstallmentEdit`.
 */
final class InstallmentEditLock
{
    /**
     * La Orden que tiene esta cuota en circulación, si la hay.
     *
     * Exige que la Orden esté activa **y** tenga su Pase vivo: es el Pase
     * el que saca el expediente del área, y una Orden sin nota todavía no
     * salió a ningún lado.
     */
    public function lockingOrder(BeneficiaryInstallment $installment): ?PaymentOrder
    {
        return PaymentOrder::query()
            ->active()
            ->where('beneficiary_installment_id', $installment->id)
            ->whereHas('pase', fn ($pase) => $pase->where('status', '!=', PaseStatus::Voided->value))
            ->with('pase')
            ->first();
    }

    /**
     * La misma pregunta sobre una Orden que quien llama ya tiene cargada.
     *
     * La pantalla del haber resuelve la Orden vigente de cada cuota para
     * mostrarla; volver a buscarla acá sería una consulta por cuota para
     * responder algo que ya está en memoria. La condición es la misma que
     * la de `lockingOrder()`, escrita una sola vez.
     */
    public function locksWith(?PaymentOrder $order): bool
    {
        return $order !== null
            && $order->status->isActive()
            && $order->pase !== null
            && $order->pase->status !== PaseStatus::Voided;
    }

    /**
     * Si la edición está trabada ahora mismo.
     *
     * La ventana abierta gana sobre el bloqueo: para eso se abrió.
     */
    public function blocks(BeneficiaryInstallment $installment): bool
    {
        if ($installment->edit_unlocked_at !== null) {
            return false;
        }

        return $this->lockingOrder($installment) !== null;
    }

    /**
     * El mensaje que la pantalla —y el Action— dan cuando traba.
     *
     * Dice qué documento la traba y cómo se destraba, porque un «no se
     * puede editar» a secas deja al operador sin siguiente paso.
     */
    public function reason(PaymentOrder $order): string
    {
        return sprintf(
            'La cuota está en circulación con la Orden %s y su Pase: el expediente salió del área. '
            .'Para corregirla hay que registrar antes el caso y el motivo.',
            $order->formatted_number,
        );
    }
}
