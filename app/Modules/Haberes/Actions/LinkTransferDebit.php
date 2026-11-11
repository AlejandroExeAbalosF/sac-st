<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Haberes\Enums\DisbursementMethod;
use App\Modules\Haberes\Enums\DisbursementStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Haberes\Support\TransferDebitPair;
use App\Modules\Haberes\Support\TransferStage;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El débito apareció en el extracto — §2.3.2 y §2.3.3 del DER.
 *
 * **Reconocer no es imputar todavía.** El movimiento queda apuntado en el
 * egreso, pero la fila de `bank_transaction_allocations` se escribe recién
 * al validar: esa tabla exige un evento financiero, y el evento del egreso
 * no existe hasta que el contador confirma (§2.3.5). La alternativa era
 * postear el asiento acá, que es lo que el DER prohíbe.
 *
 * **Y por eso esto se puede deshacer.** Mientras el egreso no esté
 * confirmado, un débito mal atribuido se desvincula sin dejar nada roto:
 * no hubo asiento ni imputación. Después ya no —lo impide el trigger—,
 * porque entonces hay un pago validado que lo referencia.
 *
 * El orden con el informe es indistinto: el §12.3 y el §12.4 describen los
 * dos, y ninguno de los dos alcanza solo.
 *
 * **Se elige la transferencia; la comisión viene sola.** Si el banco cobró
 * comisión, es el débito de la misma operación, y `TransferDebitPair` la
 * encuentra con la misma regla que usa el buscador. Vincular reserva los
 * dos: la base impide que otro egreso vivo los tome mientras éste espera
 * la validación.
 */
final class LinkTransferDebit
{
    public function __construct(
        private readonly TransferDebitPair $par,
        private readonly TransferStage $etapa,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /** @throws ValidationException */
    public function handle(
        BeneficiaryInstallment $installment,
        BankTransaction $transaction,
        ?int $actorId = null,
    ): Disbursement {
        return DB::transaction(function () use ($installment, $transaction, $actorId): Disbursement {
            $orden = $this->ordenVigente($installment);
            $egreso = $this->egresoEnCurso($installment, $orden, $actorId);

            if ($egreso->status === DisbursementStatus::Confirmed) {
                throw ValidationException::withMessages([
                    'installmentId' => 'El egreso ya está confirmado: su débito no se cambia.',
                ]);
            }

            $par = $this->par->resolve($transaction, $orden, $egreso->amount, $egreso->id, lock: true);

            if (! $par->matches()) {
                throw ValidationException::withMessages([
                    'bankTransactionId' => (string) $par->rejection(),
                ]);
            }

            $egreso->forceFill([
                'bank_transaction_id' => $transaction->id,
                'fee_bank_transaction_id' => $par->fee?->id,
                'bank_debit_observed_at' => now(),
            ])->save();

            $egreso->forceFill(['status' => $this->etapa->for($egreso)])->save();

            $this->auditar->handle('egreso.debito-reconocido', $egreso, after: [
                'bank_transaction_id' => $transaction->id,
                'fee_bank_transaction_id' => $par->fee?->id,
                'transfer_amount' => $par->transferAmount(),
                'fee_amount' => $par->feeAmount(),
                'transaction_date' => $transaction->transaction_date?->toDateString(),
                'status' => $egreso->status->value,
            ], actorId: $actorId);

            return $egreso;
        });
    }

    /**
     * Deshace el reconocimiento de un débito que era de otra Orden.
     *
     * @throws ValidationException
     */
    public function undo(Disbursement $disbursement, ?int $actorId = null, ?string $reason = null): Disbursement
    {
        return DB::transaction(function () use ($disbursement, $actorId, $reason): Disbursement {
            if ($disbursement->status === DisbursementStatus::Confirmed) {
                throw ValidationException::withMessages([
                    'installmentId' => 'El egreso ya está confirmado: su débito no se desvincula. '
                        .'Corregirlo exige revertir el pago.',
                ]);
            }

            $antes = $disbursement->bank_transaction_id;
            $comision = $disbursement->fee_bank_transaction_id;

            $disbursement->forceFill([
                'bank_transaction_id' => null,
                'fee_bank_transaction_id' => null,
                'bank_debit_observed_at' => null,
            ])->save();

            $disbursement->forceFill(['status' => $this->etapa->for($disbursement)])->save();

            $this->auditar->handle(
                'egreso.debito-desvinculado',
                $disbursement,
                before: ['bank_transaction_id' => $antes, 'fee_bank_transaction_id' => $comision],
                after: ['status' => $disbursement->status->value, 'reason' => $reason],
                actorId: $actorId,
            );

            return $disbursement;
        });
    }

    /** @throws ValidationException */
    private function egresoEnCurso(
        BeneficiaryInstallment $installment,
        PaymentOrder $orden,
        ?int $actorId,
    ): Disbursement {
        $existente = Disbursement::query()
            ->live()
            ->where('beneficiary_installment_id', $installment->id)
            ->lockForUpdate()
            ->first();

        if ($existente !== null) {
            return $existente;
        }

        /*
         * El débito llegó antes que el informe (§12.4). El egreso nace acá
         * y queda esperando el aviso del organismo.
         */
        return Disbursement::query()->create([
            'beneficiary_installment_id' => $installment->id,
            'payment_order_id' => $orden->id,
            'method' => DisbursementMethod::BankTransfer,
            'amount' => $orden->amount,
            'beneficiary_cbu_snapshot' => $orden->beneficiary_cbu_snapshot,
            'status' => DisbursementStatus::Pending,
            'created_by' => $actorId,
        ]);
    }

    /** @throws ValidationException */
    private function ordenVigente(BeneficiaryInstallment $installment): PaymentOrder
    {
        $orden = PaymentOrder::query()
            ->active()
            ->where('beneficiary_installment_id', $installment->id)
            ->first();

        if ($orden === null) {
            throw ValidationException::withMessages([
                'installmentId' => 'La cuota no tiene una Orden de Pago vigente.',
            ]);
        }

        return $orden;
    }
}
