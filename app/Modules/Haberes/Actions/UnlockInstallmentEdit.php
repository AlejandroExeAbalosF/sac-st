<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Support\InstallmentEditLock;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Abre la ventana para corregir una cuota que ya está en circulación.
 *
 * El área definió los tres pasos (Correcciones §33):
 *
 * 1. un botón para **registrar el caso y el porqué**;
 * 2. registrado eso, se habilita la edición de todos los campos;
 * 3. guardado, se vuelve a bloquear.
 *
 * Esto es el paso 1. El 3 lo hace `UpdateInstallment` al terminar de
 * guardar: la ventana se cierra sola, no queda abierta esperando que
 * alguien se acuerde de cerrarla.
 *
 * **No es un permiso, es un acto registrado.** Quien tiene la atribución
 * de editar la cuota ya la tenía; lo que esto agrega es que quede escrito
 * *por qué* se editó algo que el organismo superior ya tiene en la mano.
 * El motivo va a `audit_events` junto al antes y el después que
 * `UpdateInstallment` guarda por su cuenta, y es lo que alguien va a leer
 * el año que viene cuando la Orden y la cuota digan cosas distintas.
 */
final class UnlockInstallmentEdit
{
    public function __construct(
        private readonly InstallmentEditLock $traba,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        BeneficiaryInstallment $installment,
        string $reason,
        ?int $actorId = null,
    ): BeneficiaryInstallment {
        $motivo = trim($reason);

        if (mb_strlen($motivo) < 10) {
            throw ValidationException::withMessages([
                'reason' => 'El motivo tiene que explicar el caso: un par de palabras no alcanzan '
                    .'para entender, dentro de un año, por qué se corrigió una cuota que ya estaba '
                    .'en manos del organismo.',
            ]);
        }

        $orden = $this->traba->lockingOrder($installment);

        if ($orden === null) {
            throw ValidationException::withMessages([
                'reason' => 'Esta cuota no está trabada: se puede corregir sin registrar nada.',
            ]);
        }

        DB::transaction(function () use ($installment, $motivo, $actorId): void {
            $installment->forceFill([
                'edit_unlocked_at' => now(),
                'edit_unlock_reason' => $motivo,
                'edit_unlocked_by' => $actorId,
            ])->save();
        });

        $this->auditar->handle('cuota.edicion-habilitada', $installment, after: [
            'reason' => $motivo,
            'payment_order_id' => $orden->id,
            'payment_order_number' => $orden->formatted_number,
        ], actorId: $actorId);

        return $installment;
    }
}
