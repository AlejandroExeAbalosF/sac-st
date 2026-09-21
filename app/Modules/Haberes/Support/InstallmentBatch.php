<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Haberes\Data\InstallmentReceiptData;
use App\Modules\Haberes\Enums\InstallmentStage;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\Receipt;
use Illuminate\Support\Collection;

/**
 * Lo que una lista de cuotas necesita para no mentir, en lote.
 *
 * Las pantallas que muestran cuotas —el detalle del expediente, las filas
 * expandibles del listado, la tarjeta del haber— necesitan las mismas
 * cuatro cosas: cuánto se imputó, el recibo de ingreso, el traslado al
 * banco y el medio real. Preguntarlas por cuota sería una consulta por
 * fila; acá se resuelven de una y se reparten por id.
 *
 * ── Por qué existe ─────────────────────────────────────────────────────
 *
 * El detalle del expediente **no las pedía**, y el efecto era silencioso:
 * `cashTransfer` llegaba siempre en `null`, `effectiveMedium` caía en el
 * previsto, y el listado mostraba «Efectivo» para una cuota que ya estaba
 * depositada en el banco. Nada fallaba —la pantalla decía menos de lo que
 * creía— y por eso pasó desapercibido hasta que alguien comparó las dos
 * pantallas.
 */
final class InstallmentBatch
{
    public function __construct(
        private readonly InstallmentFunding $financiacion,
        private readonly PaymentOrderSources $fuentes,
        private readonly InstallmentStages $etapas,
    ) {}

    /**
     * @param  Collection<int, BeneficiaryInstallment>  $cuotas
     * @return array{
     *     funded: array<int, numeric-string>,
     *     receipts: array<int, Receipt>,
     *     transfers: array<int, CashToBankTransfer>,
     *     mediums: array<int, PaymentMedium|null>,
     *     stages: array<int, InstallmentStage>,
     * }
     */
    public function for(Collection $cuotas): array
    {
        /** @var list<int> $ids */
        $ids = $cuotas->pluck('id')->map(intval(...))->values()->all();

        return [
            'funded' => $this->financiacion->allocatedForMany($ids),
            'receipts' => $this->incomeReceipts($ids),
            'transfers' => $this->fuentes->transfersFor($ids),
            'mediums' => $this->financiacion->mediumForMany($ids),
            'stages' => $this->etapas->forMany($cuotas),
        ];
    }

    /**
     * El recibo de ingreso vigente de cada cuota.
     *
     * Solo los emitidos: un anulado consumió su número y alguien lo tuvo en
     * la mano, pero la cuota vuelve a necesitar uno.
     *
     * @param  list<int>  $cuotaIds
     * @return array<int, Receipt>
     */
    public function incomeReceipts(array $cuotaIds): array
    {
        if ($cuotaIds === []) {
            return [];
        }

        /** @var array<int, Receipt> $porCuota */
        $porCuota = Receipt::query()
            ->with(InstallmentReceiptData::RELATIONS)
            ->issued()
            ->where('receipt_type', ReceiptType::Income)
            ->whereIn('beneficiary_installment_id', $cuotaIds)
            ->get()
            ->keyBy('beneficiary_installment_id')
            ->all();

        return $porCuota;
    }
}
