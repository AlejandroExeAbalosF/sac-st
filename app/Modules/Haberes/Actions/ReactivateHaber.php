<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Enums\ExpedienteStatus;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Models\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deshace la anulación de un haber.
 *
 * Devuelve el haber y sus cuotas al estado que tenían antes, no a
 * «activo»: una cuota ya pagada tiene que volver pagada, y un haber
 * bloqueado tiene que recuperar su motivo. Ese estado lo guardó el evento
 * de anulación.
 */
final class ReactivateHaber
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    public function handle(Haber $haber, string $motivo): Haber
    {
        return DB::transaction(function () use ($haber, $motivo): Haber {
            $bloqueado = Haber::query()->lockForUpdate()->findOrFail($haber->id);

            if ($bloqueado->workflow_status !== HaberWorkflowStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'reason' => 'Este haber no está anulado.',
                ]);
            }

            if ($bloqueado->expediente->status === ExpedienteStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'reason' => 'El expediente está anulado: reactivalo a él primero.',
                ]);
            }

            $anulacion = AuditEvent::query()
                ->forSubject('Haber', $bloqueado->id)
                ->where('action', 'haber.anulado')
                ->first();

            $estadoPrevio = $anulacion?->before('workflow_status')
                ?? HaberWorkflowStatus::Active->value;
            $motivoPrevio = $anulacion?->before('block_reason');

            $this->restaurarCuotas($bloqueado, $anulacion?->metadata['cuotas'] ?? []);

            $bloqueado->forceFill([
                'workflow_status' => $estadoPrevio,
                'block_reason' => $motivoPrevio,
            ])->save();

            $this->auditar->handle(
                'haber.reactivado',
                $bloqueado,
                before: ['workflow_status' => HaberWorkflowStatus::Cancelled->value],
                after: ['workflow_status' => $estadoPrevio],
                metadata: [
                    'motivo' => $motivo,
                    'expediente_id' => $bloqueado->expediente_id,
                    // Si el evento de anulación no está, todo vuelve activo
                    // y hay que poder saber que se restauró a ciegas.
                    'estados_restaurados' => $anulacion !== null,
                ],
            );

            return $bloqueado->refresh();
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $previos
     */
    private function restaurarCuotas(Haber $haber, array $previos): void
    {
        $porId = collect($previos)->keyBy('id');

        foreach ($haber->installments()->get(['id']) as $cuota) {
            $previo = $porId->get($cuota->id);

            BeneficiaryInstallment::query()->whereKey($cuota->id)->update([
                'workflow_status' => $previo['workflow_status'] ?? InstallmentWorkflowStatus::Active->value,
                'block_reason' => $previo['block_reason'] ?? null,
            ]);
        }
    }
}
