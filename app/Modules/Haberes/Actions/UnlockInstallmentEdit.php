<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Support\InstallmentEditLock;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Support\Reason;
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
        Reason $motivo,
        ?int $actorId = null,
    ): BeneficiaryInstallment {
        $orden = $this->traba->lockingOrder($installment);

        if ($orden === null) {
            throw ValidationException::withMessages([
                'reason' => 'Esta cuota no está trabada: se puede corregir sin registrar nada.',
            ]);
        }

        DB::transaction(function () use ($installment, $motivo, $actorId): void {
            $installment->forceFill([
                'edit_unlocked_at' => now(),
                'edit_unlock_reason_code' => $motivo->code,
                'edit_unlock_reason' => $motivo->detail,
                'edit_unlocked_by' => $actorId,
            ])->save();
        });

        $this->auditar->handle('cuota.edicion-habilitada', $installment, after: [
            'reason' => $motivo->describe(),
            'payment_order_id' => $orden->id,
            'payment_order_number' => $orden->formatted_number,
        ], metadata: ['motivo_codigo' => $motivo->code->value], actorId: $actorId);

        return $installment;
    }
}
