<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Haberes\Models\LegacyDocument;
use App\Modules\Haberes\Models\LegacySettlement;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Anula el registro de que una cuota se pagó fuera del circuito.
 *
 * Es la salida para un error de carga: la cuota vuelve a quedar pendiente
 * y sus papeles quedan anulados, con motivo. Nada se borra.
 *
 * **Es documental.** Si el pago vino de «Pagos anteriores», el egreso
 * contable sigue en el libro y el recibo vuelve a tener disponible para
 * respaldar otra cuota: lo que se deshace es el vínculo, no el pago.
 *
 * Los papeles que la cuota tenía de antes —un recibo de ingreso cargado al
 * apartar fondos— no son del registro y no caen con él.
 */
final class VoidLegacySettlement
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    /** @throws ValidationException */
    public function handle(BeneficiaryInstallment $installment, string $reason, ?int $actorId = null): LegacySettlement
    {
        $motivo = trim($reason);

        if (mb_strlen($motivo) < 5) {
            throw ValidationException::withMessages([
                'reason' => 'El motivo tiene que explicar algo: un par de palabras no alcanzan.',
            ]);
        }

        return DB::transaction(function () use ($installment, $motivo, $actorId): LegacySettlement {
            $expedienteId = (int) Haber::query()->whereKey($installment->haber_id)->valueOrFail('expediente_id');

            Expediente::query()->lockForUpdate()->findOrFail($expedienteId);
            Haber::query()->lockForUpdate()->findOrFail($installment->haber_id);
            $cuota = BeneficiaryInstallment::query()->lockForUpdate()->findOrFail($installment->id);

            $registro = LegacySettlement::query()
                ->current()
                ->where('beneficiary_installment_id', $cuota->id)
                ->lockForUpdate()
                ->first();

            if ($registro === null || $cuota->workflow_status !== InstallmentWorkflowStatus::LegacySettled) {
                throw ValidationException::withMessages([
                    'reason' => 'La cuota no tiene un pago fuera del circuito vigente.',
                ]);
            }

            $anulacion = [
                'voided_at' => now(),
                'voided_by' => $actorId,
                'void_reason' => $motivo,
            ];

            LegacyDocument::query()
                ->current()
                ->where('legacy_settlement_id', $registro->id)
                ->get()
                ->each(fn (LegacyDocument $documento) => $documento->forceFill($anulacion)->save());

            $registro->forceFill($anulacion)->save();
            $cuota->forceFill(['workflow_status' => InstallmentWorkflowStatus::Active])->save();

            $this->auditar->handle('cuota.pago-fuera-del-circuito-anulado', $cuota, before: [
                'mode' => $registro->mode->value,
                'amount' => $registro->amount,
                'legacy_disbursement_receipt_id' => $registro->legacy_disbursement_receipt_id,
            ], metadata: ['reason' => $motivo], actorId: $actorId);

            return $registro;
        });
    }
}
