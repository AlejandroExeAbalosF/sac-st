<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Haberes\Enums\AllocationKind;
use App\Modules\Haberes\Enums\DepositTicketStatus;
use App\Modules\Haberes\Enums\DisbursementStatus;
use App\Modules\Haberes\Enums\LegacyDocumentKind;
use App\Modules\Haberes\Enums\PaymentOrderStatus;
use App\Modules\Haberes\Models\DepositTicket;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Models\LegacyDocument;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Models\Receipt;

/**
 * Qué impide dar una cuota por pagada fuera del circuito.
 *
 * Una cuota que ya se movió adentro del sistema —tiene plata asignada, un
 * comprobante, una Orden, un egreso o un recibo— no se puede declarar
 * pagada afuera: sería decir dos veces que se pagó, una de ellas sin
 * respaldo contable. Es la misma lista que mira el trigger
 * `legacy_settlement_requires_clean_installment`; acá se responde en lote
 * para que la tarjeta sepa si ofrecer el botón, y con palabras para que el
 * Action explique por qué no.
 */
final class InstallmentMovements
{
    /**
     * El primer motivo por el que cada cuota no se puede dar por pagada
     * afuera. Las que no aparecen están limpias.
     *
     * @param  list<int>  $installmentIds
     * @return array<int, string>
     */
    public function obstaclesFor(array $installmentIds): array
    {
        if ($installmentIds === []) {
            return [];
        }

        $motivos = [];

        $anotar = function (iterable $ids, string $motivo) use (&$motivos): void {
            foreach ($ids as $id) {
                $motivos[(int) $id] ??= $motivo;
            }
        };

        $anotar(
            FundingAllocation::query()
                ->whereIn('beneficiary_installment_id', $installmentIds)
                ->groupBy('beneficiary_installment_id')
                ->havingRaw(
                    'SUM(CASE WHEN allocation_kind = ? THEN -amount ELSE amount END) <> 0',
                    [AllocationKind::Reversal->value],
                )
                ->pluck('beneficiary_installment_id'),
            'La cuota tiene fondos asignados.',
        );

        $anotar(
            Receipt::query()
                ->whereIn('beneficiary_installment_id', $installmentIds)
                ->where('status', ReceiptStatus::Issued)
                ->pluck('beneficiary_installment_id'),
            'La cuota tiene un recibo del sistema emitido.',
        );

        $anotar(
            PaymentOrder::query()
                ->whereIn('beneficiary_installment_id', $installmentIds)
                ->whereNotIn('status', [PaymentOrderStatus::Voided, PaymentOrderStatus::Rejected])
                ->pluck('beneficiary_installment_id'),
            'La cuota tiene una Orden de Pago.',
        );

        $anotar(
            Disbursement::query()
                ->whereIn('beneficiary_installment_id', $installmentIds)
                ->whereNotIn('status', [DisbursementStatus::Reversed, DisbursementStatus::Failed])
                ->pluck('beneficiary_installment_id'),
            'La cuota tiene un egreso registrado.',
        );

        $anotar(
            DepositTicket::query()
                ->whereIn('beneficiary_installment_id', $installmentIds)
                ->where('status', '<>', DepositTicketStatus::Discarded->value)
                ->pluck('beneficiary_installment_id'),
            'La cuota tiene un comprobante de depósito cargado.',
        );

        return $motivos;
    }

    /** El motivo de una sola cuota, o `null` si está limpia. */
    public function obstacleFor(int $installmentId): ?string
    {
        return $this->obstaclesFor([$installmentId])[$installmentId] ?? null;
    }

    /**
     * El papel vigente de ese tipo que la cuota ya tiene, si tiene.
     *
     * Se usa para no pedir dos veces el recibo de ingreso: si ya se cargó
     * —al apartar fondos, por ejemplo—, el registro del pago lo reutiliza.
     */
    public function currentLegacyDocument(int $installmentId, LegacyDocumentKind $kind): ?LegacyDocument
    {
        return LegacyDocument::query()
            ->current()
            ->where('beneficiary_installment_id', $installmentId)
            ->where('kind', $kind->value)
            ->first();
    }
}
