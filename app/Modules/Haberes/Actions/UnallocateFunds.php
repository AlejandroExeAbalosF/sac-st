<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Haberes\Enums\AllocationKind;
use App\Modules\Haberes\Enums\PaymentOrderStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\CashToBankTransferItem;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Libera plata que estaba imputada a una cuota y la manda a la cola de
 * fondos sin identificar.
 *
 * **No es una devolución.** El DER llama así (§2.6) a devolverle plata al
 * empleador, que es otra cosa y sale del organismo. Acá el dinero no se
 * mueve de la caja ni de la cuenta: solo deja de tener dueño asignado y
 * vuelve al pozo, esperando a la cuota que corresponda. Confundir los dos
 * nombres en un sistema que maneja plata de terceros es caro.
 *
 * Es el espejo de `AllocateFundsToInstallment`, y hacía falta por dos
 * caminos distintos que terminan en el mismo lugar:
 *
 * - **Se corrigió el importe de la cuota** y quedó plata de más adentro.
 *   Se libera el excedente.
 * - **La recepción se imputó a la cuota equivocada.** Se libera todo y se
 *   vuelve a imputar donde corresponde, con la asignación de siempre.
 *
 * **No edita nada: agrega.** La asignación original queda tal como está y
 * esto escribe una fila de tipo `reversal` que la apunta y la resta. Ese
 * es el sentido de que el libro sea append-only —lo que ocurrió no se
 * reescribe— y también la razón por la que la plata sí se puede mover:
 * cada movimiento es un renglón nuevo.
 *
 * **Se postea con la fecha de hoy, no con la del error.** Un período
 * cerrado no se reabre, se corrige en el abierto: si el asiento fuera con
 * la fecha vieja, un arqueo que ya cerró bien pasaría a estar mal.
 */
final class UnallocateFunds
{
    public function __construct(
        private readonly PostJournalEntry $asentar,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @param  numeric-string  $amount
     *
     * @throws ValidationException
     */
    public function handle(
        FundingAllocation $allocation,
        string $amount,
        string $idempotencyKey,
        /*
         * El motivo **no es opcional**, y no por prolijidad: el CHECK
         * `financial_events_reversal_reason_check` ata el motivo a toda
         * reversion. Declararlo opcional dejaba que el Action se llamara
         * sin el y reventara con un error de PostgreSQL en vez de una
         * frase — el contrato del Action tiene que decir lo mismo que la
         * base, no menos.
         */
        string $notes,
        ?int $actorId = null,
    ): FundingAllocation {
        $importe = Decimal::scale($amount);

        return DB::transaction(function () use (
            $allocation, $importe, $idempotencyKey, $actorId, $notes
        ): FundingAllocation {
            $yaRevertida = FundingAllocation::query()
                ->whereRelation('allocationEvent', 'idempotency_key', $idempotencyKey)
                ->first();

            if ($yaRevertida !== null) {
                return $yaRevertida;
            }

            $haberId = $allocation->haber_id;
            $expedienteId = (int) Haber::query()->whereKey($haberId)->valueOrFail('expediente_id');

            $expedienteBloqueado = Expediente::query()->lockForUpdate()->findOrFail($expedienteId);
            $haberBloqueado = Haber::query()
                ->where('expediente_id', $expedienteBloqueado->id)
                ->lockForUpdate()
                ->findOrFail($haberId);
            $cuotaBloqueada = BeneficiaryInstallment::query()
                ->where('haber_id', $haberBloqueado->id)
                ->lockForUpdate()
                ->findOrFail($allocation->beneficiary_installment_id);

            $original = FundingAllocation::query()
                ->where('beneficiary_installment_id', $cuotaBloqueada->id)
                ->lockForUpdate()
                ->findOrFail($allocation->id);

            $this->assertReversible($original, $importe);
            $this->assertNoRespaldaOrden($cuotaBloqueada);
            $this->assertSigueEnLaCaja($original);

            $receipt = $original->fundReceipt;

            /*
             * El asiento inverso al de la asignación: la plata deja de
             * tener dueño y vuelve a la cola de no identificados, que es
             * de donde salió.
             */
            $evento = $this->asentar->handle(
                type: FinancialEventType::Reversal,
                idempotencyKey: $idempotencyKey,
                lines: [
                    EntryLine::debit(LedgerAccount::BeneficiaryFunds, $importe)
                        ->forInstallment($original->haber_id, $original->beneficiary_installment_id)
                        ->onCashBox($receipt->cash_box_id),
                    EntryLine::credit(LedgerAccount::UnassignedFunds, $importe)
                        ->from($receipt->depositor_id)
                        ->onCashBox($receipt->cash_box_id),
                ],
                date: BusinessDate::today(),
                cashBoxId: $receipt->cash_box_id,
                description: $notes,
                actorId: $actorId,
                reversalOfId: $original->allocation_event_id,
                reversalReason: $notes,
            );

            $reversion = FundingAllocation::query()->create([
                'allocation_event_id' => $evento->id,
                'fund_receipt_id' => $original->fund_receipt_id,
                'haber_id' => $original->haber_id,
                'beneficiary_installment_id' => $original->beneficiary_installment_id,
                'allocation_kind' => AllocationKind::Reversal,
                'reversal_of_id' => $original->id,
                'amount' => $importe,
                'allocated_by' => $actorId,
                'allocated_at' => now(),
                'notes' => $notes,
            ]);

            $this->auditar->handle(
                'asignacion.desasignada',
                $original,
                ['amount' => $original->amount],
                ['amount' => Decimal::sub($this->vigente($original), $importe)],
                [
                    'beneficiary_installment_id' => $original->beneficiary_installment_id,
                    'devuelto' => $importe,
                    'motivo' => $notes,
                ],
                actorId: $actorId,
            );

            return $reversion;
        });
    }

    /**
     * Cuánto de esa asignación sigue en pie.
     *
     * @return numeric-string
     */
    public function vigente(FundingAllocation $allocation): string
    {
        $revertido = FundingAllocation::query()
            ->where('reversal_of_id', $allocation->id)
            ->sum('amount');

        return Decimal::sub($allocation->amount, Decimal::scale((string) $revertido));
    }

    /**
     * Ese efectivo no puede estar ya camino al banco.
     *
     * Liberar dice «esta plata deja de ser de esta cuota y vuelve al
     * pozo». Si el traslado la llevo a la cuenta del organismo, el pozo no
     * es donde esta: el traslado quedaria apuntando a una imputacion
     * revertida, y el libro afirmando dos cosas incompatibles.
     *
     * La guarda equivalente ya estaba en `VoidCashCollection`; faltaba de
     * este lado, que es el que mas se usa.
     *
     * @throws ValidationException
     */
    private function assertSigueEnLaCaja(FundingAllocation $allocation): void
    {
        $trasladada = CashToBankTransferItem::query()
            ->where('funding_allocation_id', $allocation->id)
            ->whereRelation('transfer', 'status', '!=', CashTransferStatus::Cancelled->value)
            ->exists();

        if ($trasladada) {
            throw ValidationException::withMessages([
                'amount' => 'Ese efectivo ya se depositó en el banco: hay que cancelar primero el '
                    .'traslado para poder liberarlo.',
            ]);
        }
    }

    /** @throws ValidationException */
    private function assertNoRespaldaOrden(BeneficiaryInstallment $installment): void
    {
        $orden = PaymentOrder::query()
            ->where('beneficiary_installment_id', $installment->id)
            ->whereNotIn('status', [
                PaymentOrderStatus::Rejected->value,
                PaymentOrderStatus::Voided->value,
            ])
            ->latest('id')
            ->first();

        if ($orden === null) {
            return;
        }

        $accion = $orden->status->isActive()
            ? 'Hay que anular primero esa Orden.'
            : 'La Orden ya fue completada y esos fondos no pueden volver a quedar sin asignar.';

        throw ValidationException::withMessages([
            'allocationId' => "La imputación respalda la Orden {$orden->formatted_number}. {$accion}",
        ]);
    }

    /** @throws ValidationException */
    private function assertReversible(FundingAllocation $allocation, string $importe): void
    {
        if ($allocation->allocation_kind === AllocationKind::Reversal) {
            throw ValidationException::withMessages([
                'allocationId' => 'Eso ya es una liberación: no se libera una liberación.',
            ]);
        }

        if (Decimal::isNegative($importe) || Decimal::equals($importe, '0')) {
            throw ValidationException::withMessages([
                'amount' => 'El importe a liberar tiene que ser mayor que cero.',
            ]);
        }

        $vigente = $this->vigente($allocation);

        if (Decimal::isNegative(Decimal::sub($vigente, $importe))) {
            throw ValidationException::withMessages([
                'amount' => 'De esa imputación quedan $ '.Decimal::format($vigente)
                    .': no se puede liberar más que eso.',
            ]);
        }
    }
}
