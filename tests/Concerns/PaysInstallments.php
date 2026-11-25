<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Modules\Haberes\Actions\CollectAndIssueReceipt;
use App\Modules\Haberes\Actions\DeliverToBeneficiary;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use Illuminate\Support\Str;

/**
 * Una cuota pagada por el circuito de verdad.
 *
 * La base no deja marcarla `paid` a mano: exige su egreso confirmado. Necesita
 * los libros abiertos y la cuota en efectivo, sin cobrar.
 */
trait PaysInstallments
{
    protected function pagarPorMostrador(BeneficiaryInstallment $cuota): BeneficiaryInstallment
    {
        $actor = $this->quienAbre();

        app(CollectAndIssueReceipt::class)->handle(
            installment: $cuota,
            idempotencyKey: 'test:cobro:'.Str::uuid(),
            actorId: $actor,
        );
        app(DeliverToBeneficiary::class)->handle(
            installment: $cuota->refresh(),
            idempotencyKey: 'test:entrega:'.Str::uuid(),
            actorId: $actor,
        );

        return $cuota->refresh();
    }
}
