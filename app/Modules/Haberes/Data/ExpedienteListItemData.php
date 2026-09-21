<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Haberes\Enums\ExpedienteStatus;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Enums\InstallmentStage;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Shared\Data\LastChangeData;
use App\Modules\Shared\Models\Person;
use App\Modules\Shared\Models\Receipt;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Una fila del listado de expedientes, con sus haberes adentro.
 *
 * Los haberes viajan junto al expediente en lugar de pedirse al desplegar
 * la fila. Son pocos —el caso más grande del relevamiento tiene cinco— y
 * traerlos de una evita un pedido por cada clic, que en una lista de
 * veinte filas serían veinte viajes al servidor para leer nombres.
 */
#[TypeScript]
final class ExpedienteListItemData extends Data
{
    public function __construct(
        public int $id,
        /** Forma corta de uso diario: `125957/2026`. */
        public string $displayNumber,
        /** Número completo de SiCE: `0030064-125957/2026-0`. */
        public string $canonicalNumber,
        /**
         * Carátula. Puede faltar: lo que identifica al expediente es su
         * número, y la pantalla decide cómo se ve su ausencia.
         */
        public ?string $subject,
        public string $employerName,
        /**
         * Nombre de la persona que el expediente trae junto al de la
         * empresa: el titular, el apoderado o quien firmó. Es lo que
         * dice ese papel, no una parte del circuito, así que vive acá y
         * no en el maestro de personas.
         */
        public ?string $employerRepresentative,
        /** Observaciones internas del expediente. */
        public ?string $notes,
        /**
         * Total informado en el documento fuente.
         *
         * @var numeric-string
         */
        public string $declaredTotalAmount,
        /**
         * Suma de los haberes; se compara con el declarado.
         *
         * @var numeric-string
         */
        public string $recognizedTotalAmount,
        /** @var numeric-string */
        public string $fundedTotalAmount,
        public ExpedienteStatus $status,
        public ?string $receivedDate,
        /**
         * Cuándo se cargó al sistema, en ISO-8601.
         *
         * No es `receivedDate`, que es cuándo llegó el papel. Las dos
         * conviven en la fila y por eso la pantalla las rotula distinto:
         * confundirlas manda a buscar un expediente por la fecha
         * equivocada.
         */
        public string $createdAt,
        /** El último cambio después del alta; ausente si no hubo ninguno. */
        public ?LastChangeData $lastChange,
        /** @var list<HaberListItemData> */
        public array $haberes,
    ) {}

    /**
     * @param  array<int, int>  $ticketReceipts  id de ticket => id de recepción
     * @param  array<int, int>  $ticketAttachments  id de ticket => id de adjunto
     * @param  array<int, LastChangeData>  $haberChanges  id de haber => su último cambio
     * @param  array<int, numeric-string>  $funded  Lo imputado a cada cuota.
     * @param  array<int, Receipt>  $receipts  El recibo de ingreso por cuota.
     * @param  array<int, CashToBankTransfer>  $transfers  El traslado al banco por cuota.
     * @param  array<int, PaymentMedium|null>  $mediums  El medio real de cada cuota.
     * @param  array<int, InstallmentStage>  $stages  La etapa de cada cuota.
     */
    public static function fromModel(
        Expediente $expediente,
        array $ticketReceipts = [],
        array $ticketAttachments = [],
        ?LastChangeData $lastChange = null,
        array $haberChanges = [],
        /*
         * Lo que las cuotas necesitan para no mentir.
         *
         * Hasta acá esta pantalla no los pasaba, y el efecto era silencioso:
         * `cashTransfer` llegaba siempre en `null`, `effectiveMedium` caía
         * en el previsto y el listado mostraba «Efectivo» para una cuota ya
         * depositada. No fallaba nada, simplemente decía menos de lo que
         * creía. Todos se resuelven en lote sobre las cuotas del expediente.
         */
        array $funded = [],
        array $receipts = [],
        array $transfers = [],
        array $mediums = [],
        array $stages = [],
    ): self {
        /** @var list<HaberListItemData> $haberes */
        $haberes = $expediente->haberes
            ->map(fn ($haber): HaberListItemData => HaberListItemData::fromModel(
                haber: $haber,
                funded: $funded,
                receipts: $receipts,
                transfers: $transfers,
                mediums: $mediums,
                ticketReceipts: $ticketReceipts,
                ticketAttachments: $ticketAttachments,
                lastChange: $haberChanges[$haber->id] ?? null,
                stages: $stages,
            ))
            ->values()
            ->all();

        // `employer_id` es nullable en el esquema —el DER lo define asi para
        // que la importacion de expedientes viejos no se trabe—, y el
        // analizador no lo deduce de la relacion. La comprobacion es real,
        // no un truco para callarlo.
        $empleador = $expediente->employer;

        $reconocido = '0.00';
        $financiado = '0.00';

        foreach ($haberes as $haber) {
            /*
             * Un haber anulado se sigue mostrando —para que quede registro
             * de que estuvo— pero deja de contar. Si contara, anularlo no
             * serviría para lo único que hace falta: que el expediente
             * vuelva a cuadrar contra el total declarado.
             */
            if ($haber->status === HaberWorkflowStatus::Cancelled) {
                continue;
            }

            $reconocido = bcadd($reconocido, $haber->assignedAmount, 2);
            $financiado = bcadd($financiado, $haber->fundedAmount, 2);
        }

        return new self(
            id: $expediente->id,
            displayNumber: $expediente->display_number,
            canonicalNumber: $expediente->canonical_number ?? $expediente->display_number,
            subject: $expediente->subject,
            employerName: $empleador instanceof Person ? $empleador->name : 'Sin identificar',
            employerRepresentative: $expediente->employer_representative,
            notes: $expediente->notes,
            declaredTotalAmount: self::importe($expediente->declared_total_amount),
            recognizedTotalAmount: $reconocido,
            fundedTotalAmount: $financiado,
            status: $expediente->status,
            receivedDate: $expediente->received_date?->format('Y-m-d'),
            createdAt: $expediente->created_at->toIso8601String(),
            lastChange: $lastChange,
            haberes: $haberes,
        );
    }

    /**
     * @return numeric-string
     */
    private static function importe(?string $valor): string
    {
        if ($valor === null) {
            return '0.00';
        }

        /** @var numeric-string $valor */
        return $valor;
    }
}
