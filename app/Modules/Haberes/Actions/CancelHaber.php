<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Enums\AllocationKind;
use App\Modules\Haberes\Enums\ExpedienteStatus;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Anula un haber suelto, sin tocar el resto del expediente.
 *
 * Hasta acá la única baja era la del expediente entero, y para un haber
 * cargado de más —el beneficiario equivocado, el acta leída mal— eso
 * obligaba a anular todo y volver a cargar lo que sí estaba bien.
 *
 * Un haber anulado deja de contar en lo reconocido, que es lo que hace
 * que el expediente vuelva a cuadrar contra el total declarado.
 */
final class CancelHaber
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    public function handle(Haber $haber, string $motivo): Haber
    {
        return DB::transaction(function () use ($haber, $motivo): Haber {
            $bloqueado = Haber::query()->lockForUpdate()->findOrFail($haber->id);

            if ($bloqueado->workflow_status === HaberWorkflowStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'reason' => 'Este haber ya está anulado.',
                ]);
            }

            if ($bloqueado->expediente->status === ExpedienteStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'reason' => 'El expediente está anulado: reactivalo antes de tocar sus haberes.',
                ]);
            }

            $this->assertSinDineroImputado($bloqueado->installments()->pluck('id'));

            $estadoAnterior = $bloqueado->workflow_status;
            $motivoDeBloqueo = $bloqueado->block_reason;

            /*
             * De dónde venía cada cuota. Sin esto la vuelta atrás no es
             * fiel: reactivar dejaría todas activas, y las que ya estaban
             * pagadas volverían a figurar por cobrar.
             */
            /** @var list<array{id:int,workflow_status:string,block_reason:string|null}> $cuotas */
            $cuotas = [];

            foreach ($bloqueado->installments()->get(['id', 'workflow_status', 'block_reason']) as $cuota) {
                $cuotas[] = [
                    'id' => $cuota->id,
                    'workflow_status' => $cuota->workflow_status->value,
                    'block_reason' => $cuota->block_reason,
                ];
            }

            // `block_reason` se limpia porque hay un CHECK que exige que
            // exista si y solo si el estado es `blocked`.
            $bloqueado->installments()->update([
                'workflow_status' => InstallmentWorkflowStatus::Cancelled,
                'block_reason' => null,
            ]);

            $bloqueado->forceFill([
                'workflow_status' => HaberWorkflowStatus::Cancelled,
                'block_reason' => null,
            ])->save();

            $this->auditar->handle(
                'haber.anulado',
                $bloqueado,
                before: [
                    'workflow_status' => $estadoAnterior->value,
                    'block_reason' => $motivoDeBloqueo,
                ],
                after: ['workflow_status' => HaberWorkflowStatus::Cancelled->value],
                metadata: [
                    'motivo' => $motivo,
                    'expediente_id' => $bloqueado->expediente_id,
                    'beneficiario' => $bloqueado->beneficiary->name,
                    'importe' => $bloqueado->assigned_amount,
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
