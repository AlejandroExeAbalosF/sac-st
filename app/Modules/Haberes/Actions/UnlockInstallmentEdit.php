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
 * La corrección tiene tres pasos:
 *
 * 1. registrar el caso y el porqué —esto—;
 * 2. registrado eso, se habilita la edición de todos los campos;
 * 3. guardado, se vuelve a bloquear: lo hace `UpdateInstallment`.
 *
 * **No es un permiso, es un acto registrado.** El motivo va a
 * `audit_events` junto al antes y el después que guarda
 * `UpdateInstallment`. Ver docs/haberes.md#corregir-cuota.
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
