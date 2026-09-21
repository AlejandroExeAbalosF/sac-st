<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Haberes\Enums\AllocationKind;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Enums\PaymentOrderStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Shared\Data\QueueSampleData;
use Illuminate\Database\Eloquent\Collection;

/**
 * Las dos colas del tablero que no se pueden contar con una consulta.
 *
 * «Cuotas financiadas sin Orden» y «fondos en banco sin CBU» dependen de
 * si a la cuota le corresponde una Orden —canal mostrador o
 * transferencia— y de qué dato del maestro le falta. Esas dos respuestas
 * ya las da `PaymentOrderEligibility`, y **reescribirlas en SQL sería una
 * segunda fuente de verdad**: el día que una reversión cambie el medio de
 * una cuota, el tablero y la tarjeta del expediente dirían cosas
 * distintas y ninguna de las dos sabría que la otra existe.
 *
 * Entonces el trabajo se parte en dos. Una consulta agregada recorta un
 * superconjunto barato —cuotas vigentes, financiadas, sin Orden vigente—
 * y sobre ese puñado se pregunta caso por caso a quien sabe. Los medios
 * salen en lote con `mediumForMany`, que existe justamente para que un
 * plan de sesenta cuotas no cueste sesenta consultas.
 *
 * Las dos colas salen de la **misma pasada** porque son las dos caras de
 * la misma pregunta: la cuota que puede emitir y la que no puede porque
 * le falta el CBU.
 */
final class PendingOrderQueues
{
    /**
     * Cuántos candidatos se resuelven antes de contestar «y más».
     *
     * Son ítems de trabajo pendiente: en operación normal son decenas. El
     * tope está para que un arrastre histórico no convierta el tablero en
     * la pantalla más cara del sistema.
     */
    private const TOPE = 250;

    /** Cuántos casos concretos ofrece cada cola. */
    private const MUESTRAS = 4;

    public function __construct(
        private readonly InstallmentFunding $financiacion,
        private readonly PaymentOrderEligibility $elegibilidad,
    ) {}

    public function resolve(): PendingOrderCounts
    {
        $candidatos = $this->candidatos();
        $hayMas = $candidatos->count() > self::TOPE;
        $candidatos = $candidatos->take(self::TOPE);

        $medios = $this->financiacion->mediumForMany(
            array_values($candidatos->pluck('id')->map(intval(...))->all())
        );

        $sinOrden = 0;
        $sinCbu = 0;
        /** @var list<QueueSampleData> $muestrasSinOrden */
        $muestrasSinOrden = [];
        /** @var list<QueueSampleData> $muestrasSinCbu */
        $muestrasSinCbu = [];

        foreach ($candidatos as $cuota) {
            $estado = $this->elegibilidad->for($cuota, $medios[$cuota->id] ?? null);

            /*
             * El mostrador no es una cola: la cuota que se cobra en mano
             * no le pide nada al organismo superior, y contarla como
             * «sin Orden» sería reclamar un papel que no corresponde.
             */
            if (! $estado->applies) {
                continue;
            }

            if ($estado->canIssue()) {
                $sinOrden++;

                if (count($muestrasSinOrden) < self::MUESTRAS) {
                    $muestrasSinOrden[] = $this->muestra($cuota, null);
                }

                continue;
            }

            if ($this->leFaltaElCbu($estado)) {
                $sinCbu++;

                if (count($muestrasSinCbu) < self::MUESTRAS) {
                    $muestrasSinCbu[] = $this->muestra($cuota, 'sin CBU verificado');
                }
            }
        }

        return new PendingOrderCounts(
            withoutOrder: $sinOrden,
            withoutBankAccount: $sinCbu,
            withoutOrderSamples: $muestrasSinOrden,
            withoutBankAccountSamples: $muestrasSinCbu,
            hasMore: $hayMas,
        );
    }

    /**
     * Cuotas vigentes, financiadas y sin Orden vigente.
     *
     * Es un superconjunto a propósito: acá no se decide nada, se recorta.
     * La suma neta de reversiones es la misma expresión que usa
     * `InstallmentFunding::allocatedForMany()`, y se repite en SQL porque
     * traer todas las cuotas del sistema para filtrarlas en PHP sería
     * exactamente lo que este método evita.
     *
     * @return Collection<int, BeneficiaryInstallment>
     */
    private function candidatos(): Collection
    {
        $vigentes = array_map(
            fn (PaymentOrderStatus $estado): string => $estado->value,
            array_filter(
                PaymentOrderStatus::cases(),
                fn (PaymentOrderStatus $estado): bool => $estado->isActive(),
            ),
        );

        return BeneficiaryInstallment::query()
            ->where('workflow_status', InstallmentWorkflowStatus::Active)
            ->where('expected_amount', '>', 0)
            ->whereNotExists(function ($query) use ($vigentes): void {
                $query->selectRaw('1')
                    ->from('payment_orders')
                    ->whereColumn('payment_orders.beneficiary_installment_id', 'beneficiary_installments.id')
                    ->whereIn('payment_orders.status', $vigentes);
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
             * Lo que `PaymentOrderEligibility` va a pedir de todos modos.
             * Sin esto, el modo estricto de Eloquent revienta por lazy
             * loading en el primer candidato.
             */
            ->with(['haber.beneficiary', 'haber.expediente.employer', 'managementLabel'])
            ->orderByDesc('id')
            ->limit(self::TOPE + 1)
            ->get();
    }

    private function leFaltaElCbu(PaymentOrderReadiness $estado): bool
    {
        foreach ($estado->requiredMissing() as $campo) {
            if ($campo->code === MissingOrderField::verifiedAccount()->code) {
                return true;
            }
        }

        return false;
    }

    private function muestra(BeneficiaryInstallment $cuota, ?string $detalle): QueueSampleData
    {
        $haber = $cuota->haber;
        $expediente = $haber->expediente;

        return new QueueSampleData(
            label: $expediente->display_number.' · '.$haber->beneficiary->name,
            detail: $detalle ?? 'cuota '.$cuota->installment_number,
            href: route(
                'haberes.haber.show',
                ['expediente' => $expediente, 'haber' => $haber],
                absolute: false,
            ),
        );
    }
}
