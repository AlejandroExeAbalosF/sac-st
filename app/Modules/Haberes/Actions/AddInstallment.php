<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Data\SaveInstallmentData;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Haberes\Support\InstallmentPlanRules;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Agrega la próxima cuota prevista, por lo que se debe de ella.
 *
 * Lo que entre de más se registra después en la cuota, con
 * `RecordInstallmentExcess`: acá solo cuenta lo reconocido.
 */
final class AddInstallment
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    public function handle(Haber $haber, SaveInstallmentData $data): BeneficiaryInstallment
    {
        return DB::transaction(function () use ($haber, $data): BeneficiaryInstallment {
            $lockedHaber = Haber::query()->lockForUpdate()->findOrFail($haber->id);
            $installments = $lockedHaber->installments()->lockForUpdate()->get();
            $expected = $lockedHaber->expected_installment_count ?? $installments->count();

            if ($lockedHaber->workflow_status !== HaberWorkflowStatus::Active) {
                throw ValidationException::withMessages([
                    'form' => 'No se pueden agregar cuotas a un haber que no está activo.',
                ]);
            }

            if ($installments->count() >= $expected) {
                throw ValidationException::withMessages([
                    'form' => 'Ya están cargadas todas las cuotas previstas para este haber.',
                ]);
            }

            $sum = '0.00';

            foreach ($installments as $installment) {
                if ($installment->workflow_status !== InstallmentWorkflowStatus::Cancelled) {
                    $sum = bcadd($sum, $installment->importeAAbonar(), 2);
                }
            }

            $newSum = bcadd($sum, $data->amount, 2);
            $problema = InstallmentPlanRules::problema($newSum, $lockedHaber->importeAsignado(), $installments->count() + 1 >= $expected);

            if ($problema !== null) {
                throw ValidationException::withMessages(['amount' => $problema]);
            }

            $usedNumbers = $installments->pluck('installment_number')->all();
            $number = 1;

            while (in_array($number, $usedNumbers, true)) {
                $number++;
            }

            $installment = $lockedHaber->installments()->create([
                'installment_number' => $number,
                'expected_amount' => $data->amount,
                'management_label_id' => $data->managementLabelId,
                'due_date' => $data->dueDate,
                'description' => $data->concept,
                'expected_medium' => $data->expectedMedium,
                'notes' => $data->notes,
                'workflow_status' => InstallmentWorkflowStatus::Active,
            ]);

            $this->auditar->handle(
                'cuota.agregada',
                $installment,
                after: [
                    'installment_number' => $installment->installment_number,
                    'expected_amount' => $installment->expected_amount,
                ],
                metadata: [
                    'haber_id' => $lockedHaber->id,
                    'expediente_id' => $lockedHaber->expediente_id,
                ],
            );

            return $installment;
        });
    }
}
