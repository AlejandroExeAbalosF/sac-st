<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Enums\AllocationKind;
use App\Modules\Haberes\Enums\ExpedienteStatus;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Anula un expediente cargado por error.
 *
 * Es lo que reemplaza al borrado, y no por comodidad: el número de
 * expediente es único, así que borrarlo perdería el rastro de que estuvo
 * cargado y de quién lo sacó. Además `audit_events.subject_id` apunta a
 * una fila sin clave foránea —para que los eventos sobrevivan—, y si la
 * fila desaparece los eventos quedan señalando un identificador que ya no
 * existe: se sabe que pasó algo y no con qué.
 *
 * La anulación baja a los haberes y a sus cuotas. Un expediente anulado
 * con haberes activos abajo sería una contradicción, y esas cuotas
 * seguirían contando en los totales.
 */
final class CancelExpediente
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    public function handle(Expediente $expediente, string $motivo): Expediente
    {
        return DB::transaction(function () use ($expediente, $motivo): Expediente {
            $bloqueado = Expediente::query()->lockForUpdate()->findOrFail($expediente->id);

            if ($bloqueado->status === ExpedienteStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'reason' => 'Este expediente ya está anulado.',
                ]);
            }

            $this->assertSinDineroImputado(
                BeneficiaryInstallment::query()
                    ->whereIn('haber_id', $bloqueado->haberes()->pluck('id'))
                    ->pluck('id'),
            );

            $estadoAnterior = $bloqueado->status;

            /*
             * De dónde venía cada haber y cada cuota, antes de arrastrarlos.
             *
             * Sin esto la anulación no tiene vuelta atrás fiel: reactivar
             * dejaría todo en «activo», y un haber que estaba cerrado o
             * bloqueado volvería como si nunca lo hubiera estado. Vive en la
             * auditoría porque es exactamente lo que la auditoría registra
             * —el estado previo—, no un campo nuevo que haya que mantener.
             */
            /** @var list<array{id:int,workflow_status:string,block_reason:string|null}> $haberes */
            $haberes = [];
            $idsDeHaberes = [];

            foreach ($bloqueado->haberes()->get(['id', 'workflow_status', 'block_reason']) as $haber) {
                $idsDeHaberes[] = $haber->id;
                $haberes[] = [
                    'id' => $haber->id,
                    'workflow_status' => $haber->workflow_status->value,
                    'block_reason' => $haber->block_reason,
                ];
            }

            /** @var list<array{id:int,workflow_status:string,block_reason:string|null}> $cuotas */
            $cuotas = [];

            foreach (DB::table('beneficiary_installments')->whereIn('haber_id', $idsDeHaberes)->get(['id', 'workflow_status', 'block_reason']) as $cuota) {
                $cuotas[] = [
                    'id' => (int) $cuota->id,
                    'workflow_status' => (string) $cuota->workflow_status,
                    'block_reason' => $cuota->block_reason === null ? null : (string) $cuota->block_reason,
                ];
            }

            /*
             * `block_reason` se limpia al anular porque hay un CHECK que
             * exige que exista si y solo si el estado es `blocked`. Anular
             * un haber bloqueado sin limpiarlo lo rechaza la base, y el
             * motivo del bloqueo ya no describe la situación: el motivo que
             * vale pasa a ser el de la anulación.
             */
            $bloqueado->haberes()->update([
                'workflow_status' => HaberWorkflowStatus::Cancelled,
                'block_reason' => null,
            ]);

            DB::table('beneficiary_installments')
                ->whereIn('haber_id', $idsDeHaberes)
                ->update([
                    'workflow_status' => InstallmentWorkflowStatus::Cancelled->value,
                    'block_reason' => null,
                ]);

            $bloqueado->forceFill(['status' => ExpedienteStatus::Cancelled])->save();

            $this->auditar->handle(
                'expediente.anulado',
                $bloqueado,
                before: ['status' => $estadoAnterior->value],
                after: ['status' => ExpedienteStatus::Cancelled->value],
                metadata: [
                    'motivo' => $motivo,
                    'haberes' => $haberes,
                    'cuotas' => $cuotas,
                ],
            );

            return $bloqueado->refresh();
        });
    }

    /**
     * No se anula algo que todavía tiene plata de terceros adentro.
     *
     * Anular arrastra las cuotas a «anulada», y una cuota anulada con
     * dinero imputado es dinero del beneficiario atado a algo que el área
     * ya dio de baja: nadie lo reclama y ninguna pantalla lo lista.
     *
     * No se impide anular: se pide **desarmar el dinero primero**, que es
     * una decisión de una persona y no un efecto secundario de dar de baja
     * un expediente.
     *
     * @param  Collection<int, mixed>  $cuotaIds
     *
     * @throws ValidationException
     */
    private function assertSinDineroImputado(Collection $cuotaIds): void
    {
        if ($cuotaIds->isEmpty()) {
            return;
        }

        $conPlata = FundingAllocation::query()
            ->whereIn('beneficiary_installment_id', $cuotaIds)
            ->selectRaw('beneficiary_installment_id, SUM(CASE WHEN allocation_kind = ? THEN -amount ELSE amount END) AS neto', [AllocationKind::Reversal->value])
            ->groupBy('beneficiary_installment_id')
            ->havingRaw('SUM(CASE WHEN allocation_kind = ? THEN -amount ELSE amount END) > 0', [AllocationKind::Reversal->value])
            ->count();

        if ($conPlata > 0) {
            throw ValidationException::withMessages([
                'reason' => $conPlata === 1
                    ? 'Una cuota todavía tiene dinero imputado. Hay que liberarlo o anular su cobro antes.'
                    : "Hay {$conPlata} cuotas con dinero imputado. Hay que liberarlo o anular sus cobros antes.",
            ]);
        }
    }
}
