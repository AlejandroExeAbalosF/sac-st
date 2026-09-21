<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Enums\PaymentTerms;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Support\Facades\DB;

/**
 * Agrega un haber al expediente, con las cuotas que ya tengan importe.
 *
 * Las cuotas sin importe conocido no se crean: una cuota en cero no es un
 * derecho válido, y dejarla anotada obligaría a distinguir después entre
 * «vale cero» y «todavía no se sabe».
 *
 * El trigger de la base impide que la suma supere el derecho del haber, y
 * es diferido: las cuotas se insertan de a una y la comprobación ocurre al
 * confirmar la transacción, no fila por fila.
 */
final class AddHaber
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function handle(Expediente $expediente, array $datos, ?int $userId): Haber
    {
        return DB::transaction(function () use ($expediente, $datos, $userId): Haber {
            /** @var array<int, array<string, mixed>> $cuotas */
            $cuotas = $datos['installments'];
            $esperadas = (int) $datos['expectedInstallmentCount'];

            /*
             * El candado serializa a dos operadores cargando un haber en el
             * mismo expediente. El ordinal lo pone el modelo; lo que se
             * garantiza acá es que los dos no lean el mismo máximo y uno
             * termine comiéndose el error del índice único por algo que no
             * hizo mal.
             */
            $expediente->newQuery()->whereKey($expediente->getKey())->lockForUpdate()->first();

            $haber = $expediente->haberes()->create([
                'beneficiary_id' => $datos['beneficiaryId'],
                'beneficiary_role' => 'beneficiary',
                'default_bank_account_id' => $datos['defaultBankAccountId'] ?? null,
                'assigned_amount' => $datos['assignedAmount'],
                'expected_installment_count' => $esperadas,
                'payment_terms' => $esperadas > 1
                    ? PaymentTerms::Installments
                    : PaymentTerms::Single,
                'concept' => $datos['concept'],
                'legal_date' => $datos['legalDate'] ?? null,
                'resolution_reference' => $datos['resolutionReference'] ?? null,
                'workflow_status' => HaberWorkflowStatus::Active,
                'notes' => $datos['notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($cuotas as $cuota) {
                $haber->installments()->create([
                    'installment_number' => $cuota['number'],
                    'expected_amount' => $cuota['amount'],
                    'management_label_id' => $cuota['managementLabelId'] ?? null,
                    'due_date' => $cuota['dueDate'] ?? null,
                    /*
                     * `null` cuando la cuota no trae concepto propio, no una
                     * copia del que tiene el haber.
                     *
                     * Guardar la copia parecía inofensivo y no lo es: la otra
                     * vía que crea cuotas —la que se usa cuando el expediente
                     * vuelve con el ticket siguiente— guarda `null`. Con las
                     * dos formas conviviendo, corregir el concepto del haber
                     * alcanzaba a unas cuotas y a otras no, y el mismo haber
                     * terminaba imprimiendo dos conceptos distintos.
                     */
                    'description' => $cuota['concept'] ?? null,
                    'expected_medium' => $cuota['expectedMedium'] ?? null,
                    'notes' => $cuota['notes'] ?? null,
                    'workflow_status' => InstallmentWorkflowStatus::Active,
                ]);
            }

            $this->auditar->handle(
                'haber.reconocido',
                $haber,
                after: [
                    'beneficiary_id' => $haber->beneficiary_id,
                    'assigned_amount' => $haber->assigned_amount,
                    'expected_installment_count' => $haber->expected_installment_count,
                ],
                metadata: [
                    'expediente_id' => $expediente->id,
                    'installments' => count($cuotas),
                ],
                actorId: $userId,
            );

            return $haber;
        });
    }
}
