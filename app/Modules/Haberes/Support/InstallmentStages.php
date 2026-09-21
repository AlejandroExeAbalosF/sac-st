<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Haberes\Enums\DisbursementStatus;
use App\Modules\Haberes\Enums\InstallmentStage;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Enums\PaymentOrderStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\Receipt;
use App\Support\Money\Decimal;
use Illuminate\Support\Collection;

/**
 * En qué punto del circuito está cada cuota, en unas pocas consultas.
 *
 * Existe porque la pregunta se hace sobre listas: el detalle de un
 * expediente muestra todas las cuotas de todos sus haberes, y el listado
 * de expedientes las muestra de veinticinco expedientes a la vez.
 * Resolverla cuota por cuota sería una tanda de consultas por fila.
 *
 * ── Qué contesta y qué no ──────────────────────────────────────────────
 *
 * Contesta **dónde está el dinero**, leyendo hechos: cuánto se imputó, si
 * hay recibo de ingreso, si el efectivo se trasladó al banco, si hay Orden
 * vigente y en qué estado está el egreso.
 *
 * No contesta **si se puede operar**. Eso es `DisbursementEligibility`, que
 * además evalúa la traba del §2.2.7 y el orden de los comprobantes, y es la
 * única fuente de esa respuesta. Un botón nunca se decide con esto: se
 * decide con aquello.
 *
 * La distinción no es formal. Una cuota bloqueada por etiqueta sigue
 * estando «en caja» —el dinero está ahí, lo que no se puede es entregarlo—,
 * y mezclarlo haría que la etiqueta del listado contradiga al botón de la
 * tarjeta.
 */
final class InstallmentStages
{
    public function __construct(private readonly TransferStage $etapaDelEgreso) {}

    /**
     * @param  Collection<int, BeneficiaryInstallment>  $cuotas
     * @return array<int, InstallmentStage>
     */
    public function forMany(Collection $cuotas): array
    {
        if ($cuotas->isEmpty()) {
            return [];
        }

        /** @var list<int> $ids */
        $ids = $cuotas->pluck('id')->map(intval(...))->values()->all();

        $imputado = app(InstallmentFunding::class)->allocatedForMany($ids);
        $recibos = $this->conReciboDeIngreso($ids);
        $traslados = app(PaymentOrderSources::class)->transfersFor($ids);
        $ordenes = $this->ordenesVigentes($ids);
        $egresos = $this->egresosVivos($ids);

        $etapas = [];

        foreach ($cuotas as $cuota) {
            $id = (int) $cuota->id;

            $etapas[$id] = $this->etapaDe(
                $cuota,
                $imputado[$id] ?? '0.00',
                isset($recibos[$id]),
                $traslados[$id] ?? null,
                isset($ordenes[$id]),
                $egresos[$id] ?? null,
            );
        }

        return $etapas;
    }

    /**
     * El orden de las preguntas **es** la regla.
     *
     * Va del final hacia atrás: lo que ya ocurrió manda sobre lo que
     * todavía se espera. Un egreso confirmado hace que la cuota esté
     * pagada aunque su etiqueta la bloquee, porque el dinero ya salió.
     */
    private function etapaDe(
        BeneficiaryInstallment $cuota,
        string $imputado,
        bool $tieneRecibo,
        ?CashToBankTransfer $traslado,
        bool $tieneOrden,
        ?Disbursement $egreso,
    ): InstallmentStage {
        if ($cuota->workflow_status === InstallmentWorkflowStatus::Cancelled) {
            return InstallmentStage::Cancelled;
        }

        /*
         * El egreso primero: es el final del recorrido y lo que ya pasó no
         * lo desmiente ni una etiqueta puesta después.
         */
        if ($egreso !== null) {
            if ($egreso->status === DisbursementStatus::Confirmed) {
                return InstallmentStage::Paid;
            }

            return match ($this->etapaDelEgreso->for($egreso)) {
                DisbursementStatus::ReportReceived => InstallmentStage::TransferReported,
                DisbursementStatus::BankDebitObserved => InstallmentStage::DebitObserved,
                DisbursementStatus::ReadyForValidation => InstallmentStage::ReadyToValidate,
                default => InstallmentStage::OrderIssued,
            };
        }

        if ($cuota->workflow_status === InstallmentWorkflowStatus::Blocked) {
            return InstallmentStage::Blocked;
        }

        if ($cuota->workflow_status === InstallmentWorkflowStatus::Suspended) {
            return InstallmentStage::Suspended;
        }

        if ($tieneOrden) {
            return InstallmentStage::OrderIssued;
        }

        if ($traslado !== null) {
            return $traslado->status === CashTransferStatus::BankConfirmed
                ? InstallmentStage::AtBank
                : InstallmentStage::DepositInTransit;
        }

        /*
         * `>=` y no `==`: una cuota puede quedar imputada de más si su
         * importe bajó después, y eso no la vuelve incompleta.
         */
        if (Decimal::isNegative(Decimal::sub($imputado, $cuota->importeEsperado()))) {
            return InstallmentStage::Unfunded;
        }

        return $tieneRecibo
            ? InstallmentStage::InCashBox
            : InstallmentStage::AwaitingReceipt;
    }

    /**
     * Qué cuotas ya tienen su recibo de ingreso emitido.
     *
     * Los anulados no cuentan: consumieron número, pero la cuota vuelve a
     * necesitar uno vigente.
     *
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private function conReciboDeIngreso(array $ids): array
    {
        $filas = Receipt::query()
            ->whereIn('beneficiary_installment_id', $ids)
            ->where('receipt_type', ReceiptType::Income)
            ->where('status', ReceiptStatus::Issued)
            ->pluck('beneficiary_installment_id');

        $porCuota = [];

        foreach ($filas as $id) {
            $porCuota[(int) $id] = true;
        }

        return $porCuota;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private function ordenesVigentes(array $ids): array
    {
        $vigentes = array_map(
            fn (PaymentOrderStatus $estado): string => $estado->value,
            array_filter(
                PaymentOrderStatus::cases(),
                fn (PaymentOrderStatus $estado): bool => $estado->isActive(),
            ),
        );

        $filas = PaymentOrder::query()
            ->whereIn('beneficiary_installment_id', $ids)
            ->whereIn('status', $vigentes)
            ->pluck('beneficiary_installment_id');

        $porCuota = [];

        foreach ($filas as $id) {
            $porCuota[(int) $id] = true;
        }

        return $porCuota;
    }

    /**
     * El egreso que ocupa el lugar de cada cuota.
     *
     * Uno solo por cuota: lo impone el índice único parcial que enumera los
     * mismos estados que `DisbursementStatus::isLive()`.
     *
     * @param  list<int>  $ids
     * @return array<int, Disbursement>
     */
    private function egresosVivos(array $ids): array
    {
        $vivos = array_map(
            fn (DisbursementStatus $estado): string => $estado->value,
            array_filter(
                DisbursementStatus::cases(),
                fn (DisbursementStatus $estado): bool => $estado->isLive(),
            ),
        );

        $porCuota = [];

        foreach (
            Disbursement::query()
                ->whereIn('beneficiary_installment_id', $ids)
                ->whereIn('status', $vivos)
                ->get() as $egreso
        ) {
            $porCuota[(int) $egreso->beneficiary_installment_id] = $egreso;
        }

        return $porCuota;
    }
}
