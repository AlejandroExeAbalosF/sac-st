<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Haberes\Data\CounterPayoutRowData;
use App\Modules\Haberes\Enums\AllocationKind;
use App\Modules\Haberes\Enums\DisbursementMethod;
use App\Modules\Haberes\Enums\DisbursementStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Las cuotas que se pagan en efectivo y están listas para entregarse.
 *
 * Es la fila del mostrador antes de que haya fila: quién va a venir a
 * cobrar, cuánto, y cuánto efectivo hay que tener contado.
 *
 * ── Por qué no es una consulta y media ─────────────────────────────────
 *
 * Porque «lista para entregar» no se puede escribir en SQL sin reescribir
 * `DisbursementEligibility`, que es la única fuente de esa respuesta: ahí
 * viven la traba del §2.2.7, el recibo de ingreso que va antes, el canal
 * que decide dónde está el dinero y el depósito en tránsito que no
 * habilita nada. Una segunda versión en SQL diverge el día que una
 * reversión cambia el medio de una cuota, y entonces la planilla y la
 * tarjeta dicen cosas distintas sin que ninguna sepa de la otra.
 *
 * Entonces el trabajo se parte igual que en `PendingOrderQueues`: una
 * consulta agregada recorta un superconjunto barato y sobre ese puñado se
 * pregunta caso por caso a quien sabe. Los medios salen en lote con
 * `mediumForMany`, que existe justamente para que un plan de sesenta
 * cuotas no cueste sesenta consultas.
 *
 * El filtro final es `canPay()`, el mismo método con el que la tarjeta de
 * la cuota decide si muestra el botón de entregar. Si algún día deja de
 * alcanzar, va a dejar de alcanzar en los dos lados a la vez.
 */
final class CounterPayoutQueue
{
    /**
     * Cuántos candidatos se resuelven antes de cortar.
     *
     * Es la fila de un día de mostrador: en operación normal son decenas.
     * El tope está para que un arrastre histórico no convierta la pantalla
     * en la más cara del sistema, igual que en `PendingOrderQueues`.
     */
    private const TOPE = 250;

    public function __construct(
        private readonly InstallmentFunding $financiacion,
        private readonly DisbursementEligibility $habilitacion,
    ) {}

    /** @return list<CounterPayoutRowData> */
    public function resolve(): array
    {
        $candidatos = $this->candidatos();

        if ($candidatos->isEmpty()) {
            return [];
        }

        $ids = array_values($candidatos->pluck('id')->map(intval(...))->all());
        $medios = $this->financiacion->mediumForMany($ids);
        $cajas = $this->cajas($ids);

        $filas = [];

        foreach ($candidatos as $cuota) {
            $estado = $this->habilitacion->for($cuota, $medios[$cuota->id] ?? null);

            /*
             * `canPay()` ya exige mostrador, financiada, sin traba, con
             * recibo de ingreso y sin egreso vivo. Lo único que se agrega
             * es el efectivo: el cheque también se entrega en mano, pero no
             * sale del cajón, y contarlo acá haría que el arqueo no cierre.
             */
            if (! $estado->canPay() || $estado->method !== DisbursementMethod::Cash) {
                continue;
            }

            $filas[] = $this->fila($cuota, $estado, $cajas);
        }

        usort(
            $filas,
            // Por persona, que es como busca el cajero cuando el trabajador
            // ya está enfrente y dice su apellido.
            fn (CounterPayoutRowData $a, CounterPayoutRowData $b): int => strcoll(
                mb_strtolower($a->beneficiaryName),
                mb_strtolower($b->beneficiaryName),
            ),
        );

        return $filas;
    }

    /**
     * Cuotas vigentes, financiadas y sin egreso vivo.
     *
     * Superconjunto a propósito: acá no se decide nada, se recorta. La suma
     * neta de reversiones es la misma expresión que usan
     * `InstallmentFunding::allocatedForMany()` y `PendingOrderQueues`, y se
     * repite en SQL porque traer todas las cuotas del sistema para
     * filtrarlas en PHP sería exactamente lo que este método evita.
     *
     * @return Collection<int, BeneficiaryInstallment>
     */
    private function candidatos(): Collection
    {
        $vivos = array_map(
            fn (DisbursementStatus $estado): string => $estado->value,
            array_filter(
                DisbursementStatus::cases(),
                fn (DisbursementStatus $estado): bool => $estado->isLive(),
            ),
        );

        return BeneficiaryInstallment::query()
            ->where('workflow_status', InstallmentWorkflowStatus::Active)
            ->where('expected_amount', '>', 0)
            ->whereNotExists(function ($query) use ($vivos): void {
                $query->selectRaw('1')
                    ->from('disbursements')
                    ->whereColumn('disbursements.beneficiary_installment_id', 'beneficiary_installments.id')
                    ->whereIn('disbursements.status', $vivos);
            })
            ->whereRaw(
                'COALESCE(('
                .'SELECT SUM(CASE WHEN fa.allocation_kind = ? THEN -fa.amount ELSE fa.amount END) '
                .'FROM funding_allocations AS fa '
                .'WHERE fa.beneficiary_installment_id = beneficiary_installments.id'
                .'), 0) >= beneficiary_installments.expected_amount',
                [AllocationKind::Reversal->value],
            )
            /*
             * Lo que `DisbursementEligibility` va a pedir de todos modos.
             * Sin esto, el modo estricto de Eloquent revienta por lazy
             * loading en el primer candidato.
             */
            ->with(['haber.beneficiary', 'haber.expediente.employer', 'managementLabel'])
            ->orderBy('id')
            ->limit(self::TOPE)
            ->get();
    }

    /**
     * De qué caja sale cada cuota, en una sola consulta.
     *
     * Es la misma regla que `InstallmentCashBox`: la caja del egreso es
     * aquella en la que entró el dinero. Acá se resuelve en lote y sin
     * romper —una cuota sin recepción con caja queda sin nombre en vez de
     * abortar la planilla entera—, porque esto es un listado de lectura:
     * una fila incompleta se muestra, y una excepción deja al cajero sin
     * ninguna hoja.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function cajas(array $ids): array
    {
        $filas = DB::table('funding_allocations')
            ->join('fund_receipts', 'fund_receipts.id', '=', 'funding_allocations.fund_receipt_id')
            ->join('cash_boxes', 'cash_boxes.id', '=', 'fund_receipts.cash_box_id')
            // Espeja el scope `live()` de FundingAllocation: las reversiones
            // no aportan caja porque no aportan dinero.
            ->where('funding_allocations.allocation_kind', '!=', AllocationKind::Reversal->value)
            ->whereIn('funding_allocations.beneficiary_installment_id', $ids)
            ->orderBy('funding_allocations.id')
            ->get(['funding_allocations.beneficiary_installment_id AS cuota', 'cash_boxes.name AS caja']);

        $porCuota = [];

        foreach ($filas as $fila) {
            /*
             * La primera gana. Si dos recepciones de cajas distintas
             * financiaron la misma cuota, el problema es anterior a esta
             * planilla y no se arregla eligiendo mejor acá.
             */
            $porCuota[(int) $fila->cuota] ??= (string) $fila->caja;
        }

        return $porCuota;
    }

    /** @param  array<int, string>  $cajas */
    private function fila(
        BeneficiaryInstallment $cuota,
        DisbursementReadiness $estado,
        array $cajas,
    ): CounterPayoutRowData {
        $haber = $cuota->haber;
        $esperadas = $haber->expected_installment_count;

        return new CounterPayoutRowData(
            installmentId: (int) $cuota->id,
            expedienteId: (int) $haber->expediente_id,
            haberNumber: $haber->haber_number,
            expedienteNumber: $haber->expediente->display_number,
            beneficiaryName: $haber->beneficiary->name,
            beneficiaryDocument: $haber->beneficiary->document,
            installmentLabel: sprintf(
                'Haber %d · Cuota %d%s',
                $haber->haber_number,
                $cuota->installment_number,
                $esperadas === null ? '' : ' de '.$esperadas,
            ),
            employerName: $haber->expediente->employer?->name,
            concept: $haber->concept,
            amount: $estado->amount,
            incomeReceiptNumber: $estado->incomeReceipt?->formatted_number,
            cashBoxName: $cajas[(int) $cuota->id] ?? null,
        );
    }
}
