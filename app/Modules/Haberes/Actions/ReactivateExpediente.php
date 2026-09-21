<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Enums\ExpedienteStatus;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Models\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deshace una anulación.
 *
 * Existe porque una acción irreversible empuja a evitarla: sin vuelta
 * atrás, quien duda prefiere dejar el expediente equivocado activo antes
 * que arriesgarse, y el sistema termina con basura viva en lugar de
 * anulada.
 *
 * Devuelve cada haber y cada cuota al estado que tenía **antes** de la
 * anulación, no a «activo». Ese estado lo guardó el evento de anulación:
 * un haber que estaba cerrado tiene que volver cerrado, y uno bloqueado
 * tiene que recuperar su motivo de bloqueo.
 */
final class ReactivateExpediente
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    public function handle(Expediente $expediente, string $motivo): Expediente
    {
        return DB::transaction(function () use ($expediente, $motivo): Expediente {
            $bloqueado = Expediente::query()->lockForUpdate()->findOrFail($expediente->id);

            if ($bloqueado->status !== ExpedienteStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'reason' => 'Este expediente no está anulado.',
                ]);
            }

            $anulacion = AuditEvent::query()
                ->forSubject('Expediente', $bloqueado->id)
                ->where('action', 'expediente.anulado')
                ->first();

            $estadoPrevio = $anulacion?->old_values['status'] ?? ExpedienteStatus::Active->value;

            $this->restaurarHaberes($bloqueado, $anulacion?->metadata['haberes'] ?? []);
            $this->restaurarCuotas($bloqueado, $anulacion?->metadata['cuotas'] ?? []);

            $bloqueado->forceFill(['status' => $estadoPrevio])->save();

            $this->auditar->handle(
                'expediente.reactivado',
                $bloqueado,
                before: ['status' => ExpedienteStatus::Cancelled->value],
                after: ['status' => $estadoPrevio],
                metadata: [
                    'motivo' => $motivo,
                    // Si el evento de anulación no está —datos migrados de
                    // otro sistema, por ejemplo— todo vuelve activo y hay
                    // que poder saber que se restauró a ciegas.
                    'estados_restaurados' => $anulacion !== null,
                ],
            );

            return $bloqueado->refresh();
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $previos
     */
    private function restaurarHaberes(Expediente $expediente, array $previos): void
    {
        $porId = collect($previos)->keyBy('id');

        foreach ($expediente->haberes()->get(['id']) as $haber) {
            $previo = $porId->get($haber->id);

            $expediente->haberes()->whereKey($haber->id)->update([
                'workflow_status' => $previo['workflow_status'] ?? HaberWorkflowStatus::Active->value,
                'block_reason' => $previo['block_reason'] ?? null,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $previos
     */
    private function restaurarCuotas(Expediente $expediente, array $previos): void
    {
        $porId = collect($previos)->keyBy('id');
        $cuotas = DB::table('beneficiary_installments')
            ->whereIn('haber_id', $expediente->haberes()->pluck('id'))
            ->pluck('id');

        foreach ($cuotas as $id) {
            $previo = $porId->get($id);

            DB::table('beneficiary_installments')->where('id', $id)->update([
                'workflow_status' => $previo['workflow_status'] ?? InstallmentWorkflowStatus::Active->value,
                'block_reason' => $previo['block_reason'] ?? null,
            ]);
        }
    }
}
