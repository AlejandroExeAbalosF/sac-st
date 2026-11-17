<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\Haber;

/**
 * Bloquea una cuota de afuera hacia adentro: expediente → haber → cuota.
 *
 * Es el orden de todas las operaciones que pueden cambiar si la cuota sigue
 * vigente o cuánto tiene financiado. Bloquear siempre en el mismo orden es lo
 * que evita que dos de ellas se esperen mutuamente, y bloquear la cuota es lo
 * que serializa a quien entrega su dinero con quien lo libera.
 *
 * Solo tiene sentido dentro de una transacción.
 */
final class InstallmentLock
{
    /**
     * @return array{0: Expediente, 1: Haber, 2: BeneficiaryInstallment}
     */
    public function lock(BeneficiaryInstallment $installment): array
    {
        $expedienteId = (int) Haber::query()->whereKey($installment->haber_id)->valueOrFail('expediente_id');

        $expediente = Expediente::query()->lockForUpdate()->findOrFail($expedienteId);
        $haber = Haber::query()
            ->where('expediente_id', $expediente->id)
            ->lockForUpdate()
            ->findOrFail($installment->haber_id);
        $cuota = BeneficiaryInstallment::query()
            ->where('haber_id', $haber->id)
            ->lockForUpdate()
            ->findOrFail($installment->id);

        return [$expediente, $haber, $cuota];
    }
}
