<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Enums\HaberesReasonCode;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Haberes\Models\LegacyDocument;
use App\Modules\Haberes\Models\LegacySettlement;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Support\Reason;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Anula el registro de que una cuota se pagó fuera del circuito.
 *
 * Es la salida para un error de carga: la cuota vuelve a quedar pendiente
 * y sus papeles quedan anulados. Nada se borra. El motivo es del pago; sus
 * papeles caen con «Se anuló el pago que respaldaba», porque la lista de un
 * papel suelto es otra y el motivo de fondo ya está en el pago.
 *
 * **Es documental**, como el registro: no hay asiento que deshacer.
 *
 * Los papeles que la cuota tenía de antes —un recibo de ingreso cargado al
 * reservar fondos— no son del registro y no caen con él.
 */
final class VoidLegacySettlement
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    /** @throws ValidationException */
    public function handle(BeneficiaryInstallment $installment, Reason $motivo, ?int $actorId = null): LegacySettlement
    {
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

            $ahora = now();

            LegacyDocument::query()
                ->current()
                ->where('legacy_settlement_id', $registro->id)
                ->get()
                ->each(fn (LegacyDocument $documento) => $documento->forceFill([
                    'voided_at' => $ahora,
                    'voided_by' => $actorId,
                    'void_reason_code' => HaberesReasonCode::SettlementVoided,
                    'void_reason' => null,
                ])->save());

            $registro->forceFill([
                'voided_at' => $ahora,
                'voided_by' => $actorId,
                'void_reason_code' => $motivo->code,
                'void_reason' => $motivo->detail,
            ])->save();
            $cuota->forceFill(['workflow_status' => InstallmentWorkflowStatus::Active])->save();

            $this->auditar->handle('cuota.pago-fuera-del-circuito-anulado', $cuota, before: [
                'amount' => $registro->amount,
                'paid_on' => $registro->paid_on->toDateString(),
                'payment_medium' => $registro->payment_medium->value,
            ], metadata: $motivo->auditMetadata(), actorId: $actorId);

            return $registro;
        });
    }
}
