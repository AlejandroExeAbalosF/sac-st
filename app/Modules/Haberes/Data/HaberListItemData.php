<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Models\User;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Enums\InstallmentStage;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Shared\Data\LastChangeData;
use App\Modules\Shared\Models\Receipt;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un haber, tal como se ve al desplegar su expediente.
 *
 * Los importes viajan como string decimal, no como número: es lo que
 * garantiza que ningún punto flotante toque la plata en el camino de PHP
 * al navegador.
 */
#[TypeScript]
final class HaberListItemData extends Data
{
    public function __construct(
        public int $id,
        /** Lo necesita el enlace a la pantalla del haber. */
        public int $expedienteId,
        /**
         * Ordinal dentro del expediente: es lo que va en la dirección.
         *
         * `id` se queda para lo que no es navegación —la clave de React,
         * los diálogos— porque solo esto es único dentro del expediente y
         * solo el id lo es fuera.
         */
        public int $haberNumber,
        public string $beneficiaryName,
        public string $beneficiaryDocument,
        /**
         * Derecho total reconocido.
         *
         * @var numeric-string
         */
        public string $assignedAmount,
        /**
         * Suma neta ya asignada; se calcula desde el diario, no se guarda.
         *
         * @var numeric-string
         */
        public string $fundedAmount,
        public int $installmentCount,
        public int $paidInstallmentCount,
        public HaberWorkflowStatus $status,
        /** Motivo cuando el haber no puede avanzar. */
        public ?string $blockReason = null,
        /** Concepto base que heredan las cuotas. */
        public ?string $concept = null,
        /** Observaciones generales del haber. */
        public ?string $notes = null,
        /**
         * Cuándo se cargó y quién, igual que en la ficha del expediente.
         *
         * Estaba solo en la auditoría. El haber se carga meses después que
         * el expediente y muchas veces lo carga otra persona, así que el
         * autor del expediente no contesta por él.
         */
        public ?string $createdAt = null,
        public ?string $createdByName = null,
        /**
         * El último cambio después del alta; ausente si no hubo ninguno.
         *
         * Sale de `audit_events` y no de `updated_at`: anular el expediente
         * arrastra a sus haberes con un `update` masivo y les movería el
         * timestamp sin que nadie los haya tocado.
         */
        public ?LastChangeData $lastChange = null,
        /**
         * Las cuotas ya cargadas, en orden.
         *
         * Pueden ser menos que `installmentCount`: el expediente llega con
         * la primera y las siguientes van apareciendo con cada ticket.
         *
         * @var list<InstallmentListItemData>
         */
        public array $installments = [],
    ) {}

    /**
     * @param  array<int, numeric-string>  $funded  Lo asignado por cuota,
     *                                              calculado en lote.
     * @param  array<int, Receipt>  $receipts  El recibo de ingreso
     *                                         vigente por cuota.
     * @param  array<int, list<InstallmentAllocationData>>  $allocations  Las
     *                                                                    imputaciones
     *                                                                    vigentes
     *                                                                    por cuota.
     * @param  array<int, list<InstallmentTransferData>>  $cancelledTransfers  Los
     *                                                                         depósitos
     *                                                                         dados de
     *                                                                         baja, por
     *                                                                         cuota.
     * @param  array<int, list<InstallmentReceiptData>>  $voidedReceipts  Los
     *                                                                    intentos
     *                                                                    anulados
     *                                                                    por cuota.
     * @param  array<int, PaymentMedium|null>  $mediums  El medio real de cada
     *                                                   cuota, cuando ya
     *                                                   entró plata.
     * @param  array<int, int>  $ticketReceipts  La recepción ya registrada
     *                                           del crédito que cruzó cada
     *                                           ticket, indexada por ticket.
     * @param  array<int, int>  $ticketAttachments  El adjunto más nuevo de
     *                                              cada ticket, indexado por
     *                                              ticket.
     * @param  array<int, CashToBankTransfer>  $transfers  El traslado al
     *                                                     banco por cuota,
     *                                                     cuando el efectivo
     *                                                     no se retiro.
     * @param  array<int, InstallmentStage>  $stages  En qué punto del circuito
     *                                                está cada cuota, resuelto
     *                                                en lote.
     */
    public static function fromModel(
        Haber $haber,
        array $funded = [],
        array $receipts = [],
        array $transfers = [],
        array $allocations = [],
        array $voidedReceipts = [],
        array $cancelledTransfers = [],
        array $mediums = [],
        array $ticketReceipts = [],
        array $ticketAttachments = [],
        ?LastChangeData $lastChange = null,
        array $stages = [],
    ): self {
        $cuotas = $haber->installments;

        /** @var list<InstallmentListItemData> $listaDeCuotas */
        $listaDeCuotas = $cuotas
            ->map(fn (BeneficiaryInstallment $cuota): InstallmentListItemData => InstallmentListItemData::fromModel(
                $cuota,
                $haber->concept,
                $funded[$cuota->id] ?? null,
                $receipts[$cuota->id] ?? null,
                $transfers[$cuota->id] ?? null,
                $allocations[$cuota->id] ?? [],
                $voidedReceipts[$cuota->id] ?? [],
                $cancelledTransfers[$cuota->id] ?? [],
                $mediums[$cuota->id] ?? null,
                $ticketReceipts,
                $ticketAttachments,
                $stages[$cuota->id] ?? null,
            ))
            ->values()
            ->all();

        return new self(
            id: $haber->id,
            expedienteId: $haber->expediente_id,
            haberNumber: $haber->haber_number,
            beneficiaryName: $haber->beneficiary->name,
            beneficiaryDocument: $haber->beneficiary->document ?? '',
            assignedAmount: $haber->importeAsignado(),
            // Sale de las asignaciones del diario, que son de la etapa
            // siguiente. Hasta entonces nada esta financiado, y eso es
            // cierto: todavia no hay por donde registrar un ingreso.
            fundedAmount: '0.00',
            // Las que se esperan, no las cargadas: es lo que permite decir
            // "1 de 3" mientras faltan cuotas por llegar.
            installmentCount: $haber->expected_installment_count ?? $cuotas->count(),
            paidInstallmentCount: $cuotas
                ->where('workflow_status', InstallmentWorkflowStatus::Paid)
                ->count(),
            status: $haber->workflow_status,
            blockReason: $haber->block_reason,
            concept: $haber->concept,
            notes: $haber->notes,
            createdAt: $haber->created_at?->toIso8601String(),
            // El usuario puede haberse borrado, y el sembrado nunca tuvo
            // uno: en los dos casos el haber sigue estando.
            createdByName: $haber->creator instanceof User ? $haber->creator->name : null,
            lastChange: $lastChange,
            installments: $listaDeCuotas,
        );
    }
}
