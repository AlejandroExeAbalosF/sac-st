<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Haberes\Enums\DisbursementStatus;
use App\Modules\Haberes\Models\Disbursement;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Si las cuotas que arrastra una anulación —la del haber o la del
 * expediente— tienen un egreso vivo.
 *
 * Se mira antes que el dinero imputado: pagar no desimputa, así que sin este
 * control la cuota pagada caía en «hay que liberarlo o anular su cobro», dos
 * pasos que con un egreso confirmado también se rechazan.
 *
 * La base lo impide por su lado
 * (`beneficiary_installments_cancel_without_disbursement`).
 */
final class CancellableInstallments
{
    /**
     * @param  Collection<int, mixed>  $cuotaIds
     * @param  string  $sujeto  Lo que se quiere anular: «el haber», «el expediente».
     *
     * @throws ValidationException
     */
    public function assertWithoutDisbursements(Collection $cuotaIds, string $sujeto): void
    {
        $vivos = Disbursement::query()
            ->live()
            ->whereIn('beneficiary_installment_id', $cuotaIds)
            ->get(['id', 'status', 'bank_transaction_id', 'report_received_at']);

        if ($vivos->isEmpty()) {
            return;
        }

        $pagadas = $vivos->where('status', DisbursementStatus::Confirmed)->count();

        // Lo pagado primero: no tiene vuelta atrás, y resolver antes una
        // transferencia en curso no serviría para anular.
        if ($pagadas > 0) {
            throw ValidationException::withMessages([
                'reason' => $pagadas === 1
                    ? "Una cuota ya se le pagó al beneficiario: {$sujeto} no se puede anular."
                    : "Hay {$pagadas} cuotas ya pagadas al beneficiario: {$sujeto} no se puede anular.",
            ]);
        }

        // Con el débito reconocido no se da por fallida (`FailTransferDisbursement`).
        $conDebito = $vivos->whereNotNull('bank_transaction_id');

        if ($conDebito->isNotEmpty()) {
            $cuotas = $conDebito->count() === 1
                ? 'Una cuota tiene una transferencia con el débito ya vinculado.'
                : "Hay {$conDebito->count()} cuotas con una transferencia y su débito ya vinculado.";

            // Sin el informe, validar también se rechaza (`TransferStage::missing`).
            $paso = match (true) {
                $conDebito->whereNull('report_received_at')->isEmpty() => 'validar el pago',
                $conDebito->count() === 1 => 'registrar el informe del organismo y validar el pago',
                default => 'registrar el informe del organismo que falte y validar el pago',
            };

            throw ValidationException::withMessages([
                'reason' => "{$cuotas} Si corresponde a su Orden, hay que {$paso}; si se vinculó por error, desvincularlo.",
            ]);
        }

        throw ValidationException::withMessages([
            'reason' => $vivos->count() === 1
                ? 'Una cuota tiene una transferencia en curso. Hay que darla por fallida antes.'
                : "Hay {$vivos->count()} cuotas con una transferencia en curso. Hay que darlas por fallidas antes.",
        ]);
    }
}
