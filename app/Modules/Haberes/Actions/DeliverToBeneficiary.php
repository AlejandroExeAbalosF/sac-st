<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Enums\DisbursementMethod;
use App\Modules\Haberes\Enums\DisbursementStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Support\DisbursementEligibility;
use App\Modules\Haberes\Support\DisbursementReadiness;
use App\Modules\Haberes\Support\InstallmentCashBox;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\ChequeStatus;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La entrega en mano: el dinero saliendo de la caja hacia su dueño.
 *
 * ```text
 *   Débito   BENEFICIARY_FUNDS   deja de estar asignado a la cuota
 *   Crédito  CASH_ON_HAND        y sale de la caja
 * ```
 *
 * Con un cheque el crédito va a `CHEQUES_IN_CUSTODY` —es el asiento de
 * «Entrega del cheque al beneficiario» del §10— y no a la caja: el §2.5.2
 * es explícito en que el cheque sigue el circuito del efectivo, pero el
 * papel que cambia de manos es el cheque, no billetes. Mezclarlos rompería
 * el inventario del reverso de la planilla de caja.
 *
 * **Nace confirmado, y ésa es toda la diferencia con la transferencia.**
 * El §2.3 obliga al circuito bancario a esperar informe, débito y
 * validación porque el hecho ocurre lejos y llega en partes. Acá el
 * beneficiario está enfrente: firma y se lleva el dinero, y no hay nada
 * posterior que confirmar. Modelarlo con estados intermedios sería
 * inventarle etapas a un acto que no las tiene.
 *
 * **El importe no se tipea.** Sale de lo que la cuota tiene financiado,
 * igual que en el cobro por mostrador y por el mismo motivo: un importe
 * propuesto por el navegador es una forma de asentar un movimiento que no
 * ocurrió. Y es lo asignado y no lo esperado, porque en efectivo se
 * entrega **todo** lo recibido, incluido el excedente de redondeo (§2.4).
 */
final class DeliverToBeneficiary
{
    public function __construct(
        private readonly PostJournalEntry $asentar,
        private readonly DisbursementEligibility $elegibilidad,
        private readonly InstallmentCashBox $cajaDeLaCuota,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        BeneficiaryInstallment $installment,
        string $idempotencyKey,
        ?int $actorId = null,
        ?CarbonInterface $paymentDate = null,
        ?string $notes = null,
    ): Disbursement {
        $estado = $this->elegibilidad->for($installment);

        /*
         * Un segundo envío del mismo formulario devuelve el egreso que ya
         * existe. El asiento es idempotente por su clave, pero la fila del
         * egreso no podría serlo: el índice único la rechazaría, y el
         * operador que apretó dos veces vería un error por algo que salió
         * bien.
         */
        if ($estado->disbursement !== null) {
            return $estado->disbursement;
        }

        $this->assertPayable($estado);

        /** @var DisbursementMethod $metodo */
        $metodo = $estado->method;
        $importe = $estado->amount;
        $fecha = $paymentDate ?? now();
        $caja = $this->cajaDeLaCuota->for($installment);

        return DB::transaction(function () use (
            $installment, $metodo, $importe, $fecha, $caja, $idempotencyKey, $actorId, $notes
        ): Disbursement {
            $evento = $this->asentar->handle(
                type: $metodo->eventType(),
                idempotencyKey: $idempotencyKey,
                lines: [
                    EntryLine::debit(LedgerAccount::BeneficiaryFunds, $importe)
                        ->forInstallment($installment->haber_id, $installment->id)
                        ->onCashBox($caja),
                    EntryLine::credit($metodo->sourceAccount(), $importe)
                        ->onCashBox($caja),
                ],
                date: $fecha,
                cashBoxId: $caja,
                description: $notes,
                actorId: $actorId,
            );

            $egreso = Disbursement::query()->create([
                'financial_event_id' => $evento->id,
                'beneficiary_installment_id' => $installment->id,
                'method' => $metodo,
                'amount' => $importe,
                'status' => DisbursementStatus::Confirmed,
                'payment_date' => $fecha,
                'cash_delivered_by' => $actorId,
                'received_by_beneficiary_at' => now(),
                'notes' => $notes,
                'created_by' => $actorId,
            ]);

            if ($metodo === DisbursementMethod::Cheque) {
                $this->entregarCheques($installment);
            }

            /*
             * `paid` no significa «se cobró la cuota»: significa que el
             * egreso al beneficiario está confirmado. Lo dice el docblock
             * del enum, y es este acto el que lo produce.
             */
            $installment->forceFill(['workflow_status' => InstallmentWorkflowStatus::Paid])->save();

            $this->auditar->handle('egreso.entregado', $egreso, after: [
                'beneficiary_installment_id' => $installment->id,
                'method' => $metodo->value,
                'amount' => $importe,
                'payment_date' => $fecha->toDateString(),
                'financial_event_id' => $evento->id,
            ], actorId: $actorId);

            return $egreso;
        });
    }

    /**
     * El cheque deja la custodia.
     *
     * De su ciclo como instrumento el DER difiere casi todo al modelo
     * ideal, pero **dónde está el papel** sí es del núcleo: el reverso de
     * la planilla de caja lista los cheques uno por uno, y ese inventario
     * sale de consultar las recepciones que siguen `InCustody`. Si el
     * cheque se entregó y el estado no lo dice, el arqueo lo sigue
     * contando.
     */
    private function entregarCheques(BeneficiaryInstallment $installment): void
    {
        $recepciones = FundReceipt::query()
            ->whereIn('id', FundingAllocation::query()
                ->live()
                ->where('beneficiary_installment_id', $installment->id)
                ->select('fund_receipt_id'))
            ->where('cheque_status', ChequeStatus::InCustody)
            ->get();

        foreach ($recepciones as $recepcion) {
            $recepcion->forceFill(['cheque_status' => ChequeStatus::Delivered])->save();
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertPayable(DisbursementReadiness $estado): void
    {
        if (! $estado->isCounter()) {
            throw ValidationException::withMessages([
                'installmentId' => 'Esta cuota no se paga por mostrador: el dinero está en la cuenta del '
                    .'organismo y sale por transferencia, con su Orden de Pago.',
            ]);
        }

        if ($estado->blockedReason !== null) {
            throw ValidationException::withMessages([
                'installmentId' => $estado->blockedReason,
            ]);
        }

        if ($estado->method === null) {
            throw ValidationException::withMessages([
                'installmentId' => 'La cuota no tiene un medio con el que pagar: figura completa pero sin '
                    .'asignaciones vigentes. Es un estado inconsistente.',
            ]);
        }
    }
}
