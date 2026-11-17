<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Enums\DisbursementMethod;
use App\Modules\Haberes\Enums\DisbursementStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Support\Reason;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Da por fallida una transferencia que no llegó a pagarse.
 *
 * Es la salida de un egreso en curso que ya no va a completarse: la Orden
 * salió con un dato equivocado, el banco la rechazó o el organismo no la
 * hizo. Sin esto la Orden no se puede anular —la base no deja anularla con
 * un egreso vivo— y la cuota queda trabada.
 *
 * **No mueve dinero.** Un egreso sin validar no tiene asiento: cerrarlo solo
 * deja de ocupar el lugar de la cuota. Por eso exige que no tenga débito
 * reconocido: si el extracto muestra que el dinero salió, no falló, y lo que
 * corresponde es desvincular el débito equivocado o validar el pago.
 *
 * **El egreso lo elige quien decide.** Llega su id desde el formulario y se
 * relee bajo bloqueo: si mientras tanto otro operador ya lo cerró y nació
 * uno nuevo, el pedido viejo no alcanza al nuevo.
 *
 * El circuito y sus motivos son una propuesta sin relevar: hay que
 * confirmarlos con el área.
 */
final class FailTransferDisbursement
{
    public function __construct(
        private readonly RecordAuditEvent $auditar,
    ) {}

    /** @throws ValidationException */
    public function handle(
        BeneficiaryInstallment $installment,
        int $disbursementId,
        Reason $reason,
        ?int $actorId = null,
    ): Disbursement {
        return DB::transaction(function () use ($installment, $disbursementId, $reason, $actorId): Disbursement {
            $egreso = Disbursement::query()
                ->where('beneficiary_installment_id', $installment->id)
                ->lockForUpdate()
                ->find($disbursementId);

            if ($egreso === null || $egreso->method !== DisbursementMethod::BankTransfer) {
                throw ValidationException::withMessages([
                    'disbursementId' => 'Esa transferencia no es de esta cuota.',
                ]);
            }

            if ($egreso->status === DisbursementStatus::Failed) {
                throw ValidationException::withMessages([
                    'disbursementId' => 'Esa transferencia ya se dio por fallida.',
                ]);
            }

            if (! $egreso->status->isLive() || $egreso->status === DisbursementStatus::Confirmed) {
                throw ValidationException::withMessages([
                    'disbursementId' => 'El pago ya está validado: no se da por fallido.',
                ]);
            }

            if ($egreso->bank_transaction_id !== null) {
                throw ValidationException::withMessages([
                    'disbursementId' => 'El débito de esta transferencia está reconocido en el extracto. '
                        .'Si era de otra Orden, desvincularlo primero.',
                ]);
            }

            $antes = $egreso->status->value;

            $egreso->forceFill([
                'status' => DisbursementStatus::Failed,
                'failure_reason_code' => $reason->code->value,
                'failure_reason' => $reason->detail,
            ])->save();

            $this->auditar->handle(
                'egreso.fallido',
                $egreso,
                before: ['status' => $antes],
                after: [
                    'status' => $egreso->status->value,
                    'payment_order_id' => $egreso->payment_order_id,
                    'beneficiary_installment_id' => $installment->id,
                ],
                metadata: $reason->auditMetadata(),
                actorId: $actorId,
            );

            return $egreso;
        });
    }
}
