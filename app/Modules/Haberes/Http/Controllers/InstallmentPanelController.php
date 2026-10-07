<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Haberes\Data\InstallmentPanelData;
use App\Modules\Haberes\Data\ReceiptSubjectData;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Haberes\Support\InstallmentStages;
use App\Modules\Ledger\Enums\FundReceiptOrigin;
use Illuminate\Http\JsonResponse;

/**
 * Una cuota, para el panel lateral.
 *
 * Responde JSON y no Inertia por lo mismo que el panel de un recibo: se
 * abre encima de la pantalla que se está mirando, y una visita de Inertia
 * la reemplazaría.
 */
final class InstallmentPanelController extends Controller
{
    public function __invoke(
        BeneficiaryInstallment $installment,
        InstallmentFunding $financiacion,
        InstallmentStages $etapas,
    ): JsonResponse {
        $installment->loadMissing(['haber.beneficiary', 'haber.expediente.employer']);

        $delSistemaAnterior = FundingAllocation::query()
            ->withRemainingBalance()
            ->where('beneficiary_installment_id', $installment->id)
            ->whereHas('fundReceipt', fn ($query) => $query->where('origin', '!=', FundReceiptOrigin::Received->value))
            ->exists();

        return response()->json(new InstallmentPanelData(
            subject: ReceiptSubjectData::fromInstallment($installment),
            installmentsTotal: $installment->haber->installments()->count(),
            expectedAmount: $installment->importeEsperado(),
            fundedAmount: $financiacion->allocated($installment),
            fundedFromLegacy: $delSistemaAnterior,
            expectedMedium: $installment->expected_medium->value,
            dueDate: $installment->due_date?->toDateString(),
            stage: $etapas->forMany(collect([$installment]))[$installment->id],
        ));
    }
}
