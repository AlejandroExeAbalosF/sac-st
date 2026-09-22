<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Audit;

use App\Models\User;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\Haber;

/**
 * Adónde lleva todo lo que cuelga de una cuota.
 *
 * La orden de pago, el egreso, la asignación, el recibo y el traslado no
 * tienen pantalla propia: se miran en la tarjeta de su cuota, dentro del
 * haber. Resolverlo acá una vez evita que cada resolver arme su propio
 * camino hasta el haber —y su propio N+1—.
 */
final class InstallmentLinks
{
    /**
     * Las cuotas pedidas, con su haber y su expediente ya cargados.
     *
     * @param  list<int>  $ids
     * @return array<int, BeneficiaryInstallment>
     */
    public function load(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return BeneficiaryInstallment::query()
            ->with(['haber:id,expediente_id,haber_number,beneficiary_id', 'haber.expediente:id,display_number'])
            ->whereIn('id', array_values(array_unique($ids)))
            ->get(['id', 'haber_id', 'installment_number'])
            ->keyBy('id')
            ->all();
    }

    /** «Cuota 2 · Haber 1 · Expte. 125958/2026». */
    public function context(BeneficiaryInstallment $cuota): string
    {
        return "Cuota {$cuota->installment_number} · ".$this->haberContext($cuota->haber);
    }

    public function haberContext(Haber $haber): string
    {
        return "Haber {$haber->haber_number} · ".$this->expedienteLabel($haber->expediente);
    }

    public function expedienteLabel(Expediente $expediente): string
    {
        return "Expte. {$expediente->display_number}";
    }

    public function url(?BeneficiaryInstallment $cuota, ?User $viewer): ?string
    {
        return $cuota === null ? null : $this->haberUrl($cuota->haber, $viewer);
    }

    public function haberUrl(Haber $haber, ?User $viewer): ?string
    {
        return ($viewer?->can('expedientes.ver') ?? false)
            ? route('haberes.haber.show', [$haber->expediente, $haber])
            : null;
    }

    public function expedienteUrl(Expediente $expediente, ?User $viewer): ?string
    {
        return ($viewer?->can('expedientes.ver') ?? false)
            ? route('expedientes.show', $expediente)
            : null;
    }
}
