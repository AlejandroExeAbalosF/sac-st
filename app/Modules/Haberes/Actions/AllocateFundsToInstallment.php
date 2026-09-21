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
use App\Modules\Haberes\Models\Haber;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Le pone dueño al dinero.
 *
 * Es el último paso del circuito bancario y el que cierra esta etapa: la
 * cuota queda financiada y, con eso, en condiciones de generar su Orden de
 * Pago.
 *
 * ```text
 *   Débito   UNASSIGNED_FUNDS    deja de estar sin identificar
 *   Crédito  BENEFICIARY_FUNDS   y pasa a ser de este beneficiario
 * ```
 *
 * El dinero no se mueve de lugar —sigue en la cuenta del organismo—, lo
 * que cambia es de quién es. Por eso las dos cuentas del asiento son de
 * atribución y ninguna de ubicación.
 *
 * **Los tres controles del §11 se verifican acá y en la base.** Acá para
 * que el operador lea una explicación; en la base porque es lo que
 * garantiza que no ocurra.
 */
final class AllocateFundsToInstallment
{
    public function __construct(
        private readonly PostJournalEntry $asentar,
        private readonly InstallmentFunding $financiacion,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @param  array<string, mixed>|null  $matchExplanation  Por qué se creyó que
     *                                                       esta recepción era de esta cuota.
     *
     * @throws ValidationException
     */
    public function handle(
        FundReceipt $receipt,
        BeneficiaryInstallment $installment,
        string $amount,
        string $idempotencyKey,
        ?int $actorId = null,
        ?string $notes = null,
        ?int $matchScore = null,
        ?array $matchExplanation = null,
    ): FundingAllocation {
        $importe = Decimal::scale($amount);

        if (Decimal::isNegative($importe) || Decimal::equals($importe, '0')) {
            throw ValidationException::withMessages([
                'amount' => 'El importe a asignar tiene que ser mayor que cero.',
            ]);
        }

        return DB::transaction(function () use (
            $receipt, $installment, $importe, $idempotencyKey, $actorId, $notes, $matchScore, $matchExplanation
        ): FundingAllocation {
            // Igual que en la recepción: primero se pregunta si este hecho
            // ya está asentado, porque en un segundo envío el disponible
            // ya lo consumió el primero.
            $yaAsignado = FundingAllocation::query()
                ->whereRelation('allocationEvent', 'idempotency_key', $idempotencyKey)
                ->first();

            if ($yaAsignado !== null) {
                return $yaAsignado;
            }

            /*
             * Todas las operaciones que pueden cambiar si esta cuota sigue
             * vigente se serializan de afuera hacia adentro:
             * expediente -> haber -> cuota -> recepción.
             *
             * Bloquear solo la recepción protege su saldo, pero no alcanza
             * cuando dos recepciones distintas compiten por la misma cuota:
             * ambas podrían leer el mismo pendiente y financiarla de más.
             */
            $haberId = $installment->haber_id;
            $expedienteId = (int) Haber::query()->whereKey($haberId)->valueOrFail('expediente_id');

            $expedienteBloqueado = Expediente::query()->lockForUpdate()->findOrFail($expedienteId);
            $haberBloqueado = Haber::query()
                ->where('expediente_id', $expedienteBloqueado->id)
                ->lockForUpdate()
                ->findOrFail($haberId);
            $cuotaBloqueada = BeneficiaryInstallment::query()
                ->where('haber_id', $haberBloqueado->id)
                ->lockForUpdate()
                ->findOrFail($installment->id);
            $recepcionBloqueada = FundReceipt::query()->lockForUpdate()->findOrFail($receipt->id);

            $this->assertPuedeRecibirFondos($expedienteBloqueado, $haberBloqueado, $cuotaBloqueada);
            $this->assertFits($recepcionBloqueada, $cuotaBloqueada, $importe);
            $this->assertSameMedium($recepcionBloqueada, $cuotaBloqueada);

            $evento = $this->asentar->handle(
                type: FinancialEventType::FundsAllocated,
                idempotencyKey: $idempotencyKey,
                lines: [
                    EntryLine::debit(LedgerAccount::UnassignedFunds, $importe)
                        ->from($recepcionBloqueada->depositor_id)
                        ->onCashBox($recepcionBloqueada->cash_box_id),
                    EntryLine::credit(LedgerAccount::BeneficiaryFunds, $importe)
                        ->forInstallment($cuotaBloqueada->haber_id, $cuotaBloqueada->id)
                        ->onCashBox($recepcionBloqueada->cash_box_id),
                ],
                date: $recepcionBloqueada->received_date,
                cashBoxId: $recepcionBloqueada->cash_box_id,
                description: $notes,
                actorId: $actorId,
            );

            $asignacion = FundingAllocation::query()->create([
                'allocation_event_id' => $evento->id,
                'fund_receipt_id' => $recepcionBloqueada->id,
                'haber_id' => $cuotaBloqueada->haber_id,
                'beneficiary_installment_id' => $cuotaBloqueada->id,
                'allocation_kind' => AllocationKind::Allocation,
                'amount' => $importe,
                'allocated_by' => $actorId,
                'allocated_at' => now(),
                'match_score' => $matchScore,
                'match_explanation' => $matchExplanation,
                'notes' => $notes,
            ]);

            $this->auditar->handle('cuota.financiada', $asignacion, after: [
                'fund_receipt_id' => $recepcionBloqueada->id,
                'beneficiary_installment_id' => $cuotaBloqueada->id,
                'amount' => $importe,
                'financiada_completa' => $this->financiacion->isFullyFunded($cuotaBloqueada->refresh()),
            ], actorId: $actorId);

            return $asignacion;
        });
    }

    /** @throws ValidationException */
    private function assertPuedeRecibirFondos(
        Expediente $expediente,
        Haber $haber,
        BeneficiaryInstallment $installment,
    ): void {
        if ($expediente->status === ExpedienteStatus::Cancelled) {
            throw ValidationException::withMessages([
                'installmentId' => 'El expediente está anulado: no se le puede imputar dinero.',
            ]);
        }

        if (in_array($haber->workflow_status, [HaberWorkflowStatus::Cancelled, HaberWorkflowStatus::Closed], true)) {
            throw ValidationException::withMessages([
                'installmentId' => 'El haber está anulado o cerrado: no se le puede imputar dinero.',
            ]);
        }

        if (in_array($installment->workflow_status, [InstallmentWorkflowStatus::Cancelled, InstallmentWorkflowStatus::Paid], true)) {
            throw ValidationException::withMessages([
                'installmentId' => 'La cuota está anulada o pagada: no se le puede imputar dinero.',
            ]);
        }
    }

    /**
     * Invariante 2, por los dos lados: ni se asigna más de lo que entró,
     * ni se financia una cuota de más.
     *
     * @throws ValidationException
     */
    private function assertFits(
        FundReceipt $receipt,
        BeneficiaryInstallment $installment,
        string $amount,
    ): void {
        $sinAsignar = $this->financiacion->unallocatedForUpdate($receipt);

        if (Decimal::isNegative(Decimal::sub($sinAsignar, $amount))) {
            throw ValidationException::withMessages([
                'amount' => sprintf(
                    'De la recepción quedan $ %s sin asignar y se están pidiendo $ %s.',
                    Decimal::format($sinAsignar),
                    Decimal::format($amount),
                ),
            ]);
        }

        $falta = $this->financiacion->remaining($installment);

        if (Decimal::isNegative(Decimal::sub($falta, $amount))) {
            throw ValidationException::withMessages([
                'amount' => sprintf(
                    'A la cuota le faltan $ %s y se están asignando $ %s.',
                    Decimal::format($falta),
                    Decimal::format($amount),
                ),
            ]);
        }
    }

    /**
     * Invariante 4: una cuota se financia con un solo medio.
     *
     * El recibo de ingreso imprime **un** medio, en singular. Mezclarlos
     * haría que ese papel mienta, y es el que después respalda la Orden.
     *
     * @throws ValidationException
     */
    private function assertSameMedium(FundReceipt $receipt, BeneficiaryInstallment $installment): void
    {
        $fijado = $this->financiacion->medium($installment);

        if ($fijado !== null && $fijado !== $receipt->medium) {
            throw ValidationException::withMessages([
                'fund_receipt_id' => sprintf(
                    'La cuota ya se está financiando por %s y esta recepción entró por %s. '
                    .'Una cuota se financia con un solo medio.',
                    mb_strtolower($fijado->label()),
                    mb_strtolower($receipt->medium->label()),
                ),
            ]);
        }
    }
}
