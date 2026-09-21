<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Enums\InstallmentStage;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Shared\Data\LastChangeData;
use App\Modules\Shared\Models\Person;
use App\Modules\Shared\Models\Receipt;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un haber como fila de su propio listado.
 *
 * No es `HaberListItemData`: ese vive adentro del expediente, que ya puso
 * el número y el empleador arriba, y por eso puede no repetirlos. Acá el
 * haber viaja suelto, así que trae el contexto que lo ubica —de qué
 * expediente salió y contra qué empleador— o la fila no se entiende.
 *
 * Las cuotas vienen, igual que en el acordeón del expediente: la fila
 * cerrada dice cuántas hay y cuántas se pagaron, y al desplegarse las
 * muestra. Lo que sigue viviendo solo en la pantalla del haber es operar
 * sobre ellas —el recibo, las imputaciones, el traslado—, que necesita a
 * la vista lo que esta fila no trae.
 */
#[TypeScript]
final class HaberRowData extends Data
{
    public function __construct(
        public int $id,
        public int $expedienteId,
        /** Ordinal dentro del expediente: es lo que va en la dirección. */
        public int $haberNumber,
        /** Forma corta de uso diario: `125957/2026`. */
        public string $expedienteNumber,
        public string $employerName,
        public string $beneficiaryName,
        public string $beneficiaryDocument,
        /** Concepto base que heredan las cuotas. */
        public ?string $concept,
        /**
         * Derecho total reconocido.
         *
         * @var numeric-string
         */
        public string $assignedAmount,
        /** @var numeric-string */
        public string $fundedAmount,
        /** Las esperadas, que pueden ser más que las cargadas. */
        public int $installmentCount,
        public int $loadedInstallmentCount,
        public int $paidInstallmentCount,
        public HaberWorkflowStatus $status,
        /** Motivo cuando el haber no puede avanzar. */
        public ?string $blockReason,
        /** Cuándo llegó el expediente que lo trajo. */
        public ?string $receivedDate,
        /**
         * Cuándo se cargó el haber, en ISO-8601.
         *
         * Es del haber y no del expediente: un acto puede reconocer cinco
         * y cargarse en días distintos.
         */
        public string $createdAt,
        /**
         * El último cambio después del alta; ausente si no hubo ninguno.
         *
         * Para un haber suele estar vacío, y eso es información: se anula
         * o se reactiva, pero no se corrige —lo que se corrige son sus
         * cuotas, que tienen su propio historial—.
         */
        public ?LastChangeData $lastChange,
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
     * @param  array<int, Receipt>  $receipts  El recibo de ingreso vigente
     *                                         por cuota.
     * @param  array<int, CashToBankTransfer>  $transfers  El traslado al
     *                                                     banco por cuota,
     *                                                     cuando el efectivo
     *                                                     no se retiró.
     * @param  array<int, PaymentMedium|null>  $mediums  El medio real de cada
     *                                                   cuota, cuando ya
     *                                                   entró plata.
     * @param  array<int, InstallmentStage>  $stages  En qué punto del
     *                                                circuito está cada
     *                                                cuota, resuelto en lote.
     */
    public static function fromModel(
        Haber $haber,
        ?LastChangeData $lastChange = null,
        array $funded = [],
        array $receipts = [],
        array $transfers = [],
        array $mediums = [],
        array $stages = [],
    ): self {
        $expediente = $haber->expediente;
        $empleador = $expediente->employer;

        /** @var list<InstallmentListItemData> $cuotas */
        $cuotas = $haber->installments
            ->map(fn (BeneficiaryInstallment $cuota): InstallmentListItemData => InstallmentListItemData::fromModel(
                $cuota,
                $haber->concept,
                $funded[$cuota->id] ?? null,
                $receipts[$cuota->id] ?? null,
                $transfers[$cuota->id] ?? null,
                actualMedium: $mediums[$cuota->id] ?? null,
                stage: $stages[$cuota->id] ?? null,
            ))
            ->values()
            ->all();

        return new self(
            id: $haber->id,
            expedienteId: $haber->expediente_id,
            haberNumber: $haber->haber_number,
            expedienteNumber: $expediente->display_number,
            employerName: $empleador instanceof Person ? $empleador->name : 'Sin identificar',
            beneficiaryName: $haber->beneficiary->name,
            beneficiaryDocument: $haber->beneficiary->document ?? '',
            concept: $haber->concept,
            assignedAmount: $haber->importeAsignado(),
            /*
             * Sale de las asignaciones del diario, que son de la etapa
             * siguiente. Hasta entonces nada está financiado, y eso es
             * cierto: todavía no hay por dónde registrar un ingreso.
             */
            fundedAmount: '0.00',
            installmentCount: $haber->expected_installment_count ?? $haber->installments_count,
            loadedInstallmentCount: $haber->installments_count,
            paidInstallmentCount: $haber->paid_installments_count,
            status: $haber->workflow_status,
            blockReason: $haber->block_reason,
            receivedDate: $expediente->received_date?->format('Y-m-d'),
            createdAt: $haber->created_at->toIso8601String(),
            lastChange: $lastChange,
            installments: $cuotas,
        );
    }
}
