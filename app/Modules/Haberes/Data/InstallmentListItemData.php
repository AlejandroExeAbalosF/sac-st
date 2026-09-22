<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Haberes\Enums\DepositTicketStatus;
use App\Modules\Haberes\Enums\ExpectedMedium;
use App\Modules\Haberes\Enums\InstallmentStage;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\DepositTicket;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Shared\Models\Receipt;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Una cuota, tal como se ve en el detalle del expediente.
 *
 * El concepto viaja resuelto: se carga en el haber y la cuota lo hereda,
 * pero cada una puede sobrescribirlo cuando difiere —el caso Tinte del
 * relevamiento son dos cuotas del mismo haber con conceptos distintos—.
 */
#[TypeScript]
final class InstallmentListItemData extends Data
{
    public function __construct(
        public int $id,
        public int $number,
        /** @var numeric-string */
        public string $expectedAmount,
        public InstallmentWorkflowStatus $status,
        public ?string $concept,
        /** T.CONOC., P.P. HOMOL., PAGAR. */
        public ?string $managementLabel,
        public ?int $managementLabelId,
        /** Si esa etiqueta impide emitir la Orden de Pago. */
        public bool $blocksPayment,
        public ?string $dueDate,
        /**
         * Medio previsto. Es una anticipación del circuito, no una
         * decisión: el medio real lo fija la primera recepción vinculada.
         */
        public ExpectedMedium $expectedMedium,
        /**
         * El medio que hoy describe a la cuota: el real si ya hay plata,
         * el previsto si todavía no.
         *
         * La pantalla decide con esto y no con `expectedMedium`. El
         * previsto es una expectativa del expediente y se edita libremente
         * —hasta el pase de pago, todo se corrige—, así que puede quedar
         * contradiciendo a un hecho ya asentado. Narrar la expectativa
         * como si fuera el hecho es lo que hacía que una cuota financiada
         * por transferencia dijera «se cobra por mostrador».
         */
        public ?string $effectiveMedium,
        /** Observaciones libres; no se imprimen en el comprobante. */
        public ?string $notes,
        /** Concepto propio; `null` significa que hereda el del haber. */
        public ?string $ownConcept,
        /**
         * El comprobante de depósito, si el expediente ya lo trajo.
         *
         * Viaja entero y no solo su identificador: el modal de la cuota lo
         * muestra y lo corrige sin ir a buscarlo, que es lo que evita
         * salir del haber para arreglar un dígito mal tipeado.
         */
        public ?InstallmentTicketData $depositTicket,
        /**
         * Cuánto tiene financiado y cuánto le falta.
         *
         * Se calcula, nunca se lee de una columna (§5.1). Es lo que
         * decide si la tarjeta puede ofrecer el recibo: un ingreso
         * parcial no genera comprobante de ningún tipo (§2.1.14).
         *
         * @var numeric-string
         */
        public string $fundedAmount,
        /** @var numeric-string */
        public string $remainingAmount,
        /**
         * Lo imputado de más, si el importe de la cuota bajó después.
         *
         * Se muestra en vez de esconderse: `remainingAmount` recorta los
         * negativos a cero, así que sin esto la cuota diría «financiada
         * por completo» con plata de más adentro.
         *
         * @var numeric-string
         */
        public string $overAllocatedAmount,
        /**
         * Las imputaciones vigentes, para poder devolver dinero.
         *
         * @var list<InstallmentAllocationData>
         */
        public array $allocations,
        public bool $isFullyFunded,
        /** El recibo de ingreso, si ya se emitió. */
        public ?InstallmentReceiptData $incomeReceipt,
        /**
         * Los intentos anulados, del más nuevo al más viejo.
         *
         * Un comprobante anulado no desaparece: consumió su número y
         * alguien lo tuvo en la mano. Verlos con su motivo es lo que
         * explica por qué el vigente tiene el número que tiene.
         *
         * @var list<InstallmentReceiptData>
         */
        public array $voidedReceipts,
        /**
         * El traslado al banco, si el efectivo no se retiró y se depositó.
         *
         * Es lo que decide qué ofrece la tarjeta: sin traslado, depositar;
         * en tránsito, buscar la acreditación; acreditado, nada.
         */
        public ?InstallmentTransferData $cashTransfer,
        /**
         * Los depósitos que se cargaron y se dieron de baja.
         *
         * Se muestran por lo mismo que los recibos anulados: la tarjeta no
         * trae los cancelados, así que sin esto un depósito cargado y
         * deshecho desaparecía y nadie podía saber que existió.
         *
         * @var list<InstallmentTransferData>
         */
        public array $cancelledTransfers,
        /**
         * Cuándo se cargó la cuota.
         *
         * Es lo más parecido que el sistema tiene a la fecha en que el
         * dinero entró: la cuota se carga cuando llega el expediente, y el
         * expediente llega con el pago. De ahí sale la fecha propuesta al
         * cobrar por mostrador.
         */
        public string $createdAt,
        /** Versión para impedir que dos operadores se pisen. */
        public string $updatedAt,
        /**
         * En qué punto del circuito está, más fino que `status`.
         *
         * `status` tiene tres respuestas para todo el recorrido, así que
         * una cuota con la Orden emitida figura como «Pendiente» igual que
         * una que todavía no cobró un peso. Esto dice dónde está el dinero.
         *
         * Llega calculado en lote —`InstallmentStages::forMany()`— porque
         * las pantallas que lo muestran listan decenas de cuotas.
         */
        public ?InstallmentStage $stage = null,
    ) {}

    /**
     * @param  numeric-string|null  $fundedAmount  Llega calculado en lote:
     *                                             preguntarlo por cuota
     *                                             sería una consulta por
     *                                             fila en un plan que puede
     *                                             tener sesenta.
     * @param  list<InstallmentAllocationData>  $allocations  Las imputaciones
     *                                                        vigentes, tambien
     *                                                        en lote.
     * @param  list<InstallmentTransferData>  $cancelledTransfers  Los depósitos dados de baja.
     * @param  list<InstallmentReceiptData>  $voidedReceipts  Los intentos
     *                                                        anulados, del
     *                                                        mas nuevo al
     *                                                        mas viejo.
     * @param  array<int, int>  $ticketFundReceipts  La recepción nacida del
     *                                               crédito que cruzó cada
     *                                               ticket, por ticket.
     * @param  array<int, int>  $ticketAttachments  La foto de cada ticket,
     *                                              también por ticket.
     */
    public static function fromModel(
        BeneficiaryInstallment $cuota,
        ?string $haberConcept = null,
        ?string $fundedAmount = null,
        ?Receipt $incomeReceipt = null,
        ?CashToBankTransfer $cashTransfer = null,
        array $allocations = [],
        array $voidedReceipts = [],
        array $cancelledTransfers = [],
        ?PaymentMedium $actualMedium = null,
        /*
         * Van indexados por ticket y no por cuota: una cuota puede tener
         * más de un comprobante vigente —el depósito fraccionado del
         * §2.1.9— y cuál se muestra se decide acá, no en la consulta.
         */
        array $ticketFundReceipts = [],
        array $ticketAttachments = [],
        ?InstallmentStage $stage = null,
    ): self {
        $etiqueta = $cuota->managementLabel;

        $financiado = $fundedAmount ?? '0.00';
        $falta = Decimal::sub($cuota->importeEsperado(), $financiado);
        $sobra = Decimal::sub($financiado, $cuota->importeEsperado());
        $sobra = Decimal::isNegative($sobra) ? '0.00' : $sobra;
        $falta = Decimal::isNegative($falta) ? '0.00' : $falta;

        /*
         * Sin `getAttribute`: el modelo corre con
         * `preventAccessingMissingAttributes`, y la relación solo está
         * cargada cuando la consulta hizo el `with`.
         */
        $tickets = $cuota->relationLoaded('depositTickets') ? $cuota->depositTickets : null;
        $ticket = $tickets?->first(
            fn (DepositTicket $t): bool => $t->status !== DepositTicketStatus::Discarded,
        );

        return new self(
            id: $cuota->id,
            number: $cuota->installment_number,
            expectedAmount: $cuota->importeEsperado(),
            status: $cuota->workflow_status,
            concept: $cuota->description ?? $haberConcept,
            managementLabel: $etiqueta?->code,
            managementLabelId: $cuota->management_label_id,
            blocksPayment: (bool) $etiqueta?->blocks_payment,
            dueDate: $cuota->due_date?->format('Y-m-d'),
            expectedMedium: $cuota->expected_medium,
            effectiveMedium: $actualMedium->value ?? $cuota->expected_medium->value,
            notes: $cuota->notes,
            ownConcept: $cuota->description,
            depositTicket: $ticket === null
                ? null
                : InstallmentTicketData::fromModel($ticket, $ticketFundReceipts, $ticketAttachments),
            fundedAmount: $financiado,
            remainingAmount: $falta,
            overAllocatedAmount: $sobra,
            allocations: $allocations,
            isFullyFunded: Decimal::equals($falta, '0'),
            incomeReceipt: $incomeReceipt === null
                ? null
                : InstallmentReceiptData::fromModel($incomeReceipt),
            voidedReceipts: $voidedReceipts,
            cancelledTransfers: $cancelledTransfers,
            cashTransfer: $cashTransfer === null
                ? null
                : InstallmentTransferData::fromModel($cashTransfer),
            createdAt: BusinessDate::fromInstant($cuota->created_at)->toDateString(),
            updatedAt: $cuota->updated_at->toISOString(),
            stage: $stage,
        );
    }
}
