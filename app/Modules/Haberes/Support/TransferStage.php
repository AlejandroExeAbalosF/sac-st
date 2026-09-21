<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Haberes\Enums\DisbursementStatus;
use App\Modules\Haberes\Models\Disbursement;

/**
 * En qué etapa está un egreso por transferencia.
 *
 * **El estado se deriva, no se marca a mano.** Es la misma regla que rige
 * los saldos (§5.1) y por el mismo motivo: un estado que alguien escribe
 * puede contradecir a los datos que dice resumir. Acá los datos son dos
 * fechas —cuándo informó el organismo y cuándo se reconoció el débito— y
 * el estado es exactamente su combinación.
 *
 * **Los dos órdenes son válidos**, y es lo que el DER describe en el §12.3
 * y el §12.4: normalmente el organismo informa primero y el débito aparece
 * después, pero también pasa al revés. Ninguno de los dos alcanza solo
 * —invariantes 12 y 13— y por eso hay un estado propio para cada mitad:
 * quien mira la cola sabe qué está esperando cada egreso.
 */
final class TransferStage
{
    /**
     * La etapa que le corresponde por lo que tiene registrado.
     *
     * No toca `confirmed`: confirmar es una decisión del contador, no una
     * consecuencia de que se hayan juntado los papeles. Es justamente la
     * distinción del §2.3.4 —cotejar es un acto— y sin ella el sistema
     * daría por pagado un egreso que nadie miró.
     */
    public function for(Disbursement $disbursement): DisbursementStatus
    {
        if ($disbursement->status === DisbursementStatus::Confirmed) {
            return DisbursementStatus::Confirmed;
        }

        $informe = $disbursement->report_received_at !== null;
        $debito = $disbursement->bank_transaction_id !== null;

        return match (true) {
            $informe && $debito => DisbursementStatus::ReadyForValidation,
            $informe => DisbursementStatus::ReportReceived,
            $debito => DisbursementStatus::BankDebitObserved,
            default => DisbursementStatus::Pending,
        };
    }

    /** Lo que le falta para que el contador pueda validarlo, en una frase. */
    public function missing(Disbursement $disbursement): ?string
    {
        return match ($this->for($disbursement)) {
            DisbursementStatus::Pending => 'Falta el informe del organismo y el débito en el extracto.',
            DisbursementStatus::ReportReceived => 'Falta que el débito aparezca en el extracto. '
                .'Un informe sin débito no genera egreso.',
            DisbursementStatus::BankDebitObserved => 'Falta el informe del organismo. '
                .'Un débito sin informe no genera egreso.',
            default => null,
        };
    }
}
