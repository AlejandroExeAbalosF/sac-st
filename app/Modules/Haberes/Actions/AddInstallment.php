<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Data\SaveInstallmentData;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Agrega la próxima cuota prevista sin permitir exceder el derecho del haber. */
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
                    $sum = bcadd($sum, $installment->importeEsperado(), 2);
                }
            }

            $newSum = bcadd($sum, $data->amount, 2);
            $this->validateSum($newSum, $lockedHaber->importeAsignado(), $installments->count() + 1 >= $expected);

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

    /**
     * @param  numeric-string  $sum
     * @param  numeric-string  $assigned
     */
    private function validateSum(string $sum, string $assigned, bool $completesPlan): void
    {
        if (bccomp($sum, $assigned, 2) === 1) {
            throw ValidationException::withMessages([
                'amount' => 'El importe hace que las cuotas superen el total reconocido del haber.',
            ]);
        }

        if ($completesPlan && bccomp($sum, $assigned, 2) !== 0) {
            $remaining = bcsub($assigned, $sum, 2);

            throw ValidationException::withMessages([
                'amount' => "Es la última cuota prevista: debe completar exactamente el haber. Faltan {$remaining}.",
            ]);
        }
    }
}
