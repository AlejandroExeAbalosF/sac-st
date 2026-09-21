<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Haberes\Enums\AllocationKind;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;
use App\Support\Money\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cuánta plata tiene una cuota, y cuánta le queda a una recepción.
 *
 * **Todo se calcula; nada se guarda.** Es el §5.1 del DER: *«Los saldos se
 * calculan; no son contadores editables»*. La tentación de una columna
 * `funded_amount` en `beneficiary_installments` es real —una consulta menos
 * en cada listado— y es exactamente el error que este sistema no puede
 * permitirse: un contador se desincroniza en la primera reversión y nadie
 * se entera hasta que el arqueo no cierra.
 *
 * Vive en un solo lugar para que la respuesta sea una sola. Si la pantalla
 * de la cuota y la del recibo calcularan cada una lo suyo, tarde o
 * temprano dirían cosas distintas.
 */
final class InstallmentFunding
{
    /**
     * Lo que la cuota tiene financiado, neto de reversiones.
     *
     * @return numeric-string
     */
    public function allocated(BeneficiaryInstallment $installment): string
    {
        return $this->netSum(
            FundingAllocation::query()->where('beneficiary_installment_id', $installment->id)
        );
    }

    /**
     * Lo asignado a muchas cuotas, en una sola consulta.
     *
     * El plan de un haber puede tener sesenta cuotas, y la tarjeta de cada
     * una necesita saber si está completa para ofrecer el recibo.
     * Preguntárselo una por una sería el problema de siempre.
     *
     * @param  list<int>  $installmentIds
     * @return array<int, numeric-string> id de cuota => asignado
     */
    public function allocatedForMany(array $installmentIds): array
    {
        if ($installmentIds === []) {
            return [];
        }

        $filas = FundingAllocation::query()
            ->whereIn('beneficiary_installment_id', $installmentIds)
            ->groupBy('beneficiary_installment_id')
            ->selectRaw(
                'beneficiary_installment_id, '
                .'COALESCE(SUM(CASE WHEN allocation_kind = ? THEN -amount ELSE amount END), 0) AS neto',
                [AllocationKind::Reversal->value],
            )
            ->get();

        $porCuota = [];

        foreach ($filas as $fila) {
            /** @var object{beneficiary_installment_id: int, neto: string} $fila */
            $porCuota[(int) $fila->beneficiary_installment_id] = Decimal::scale((string) $fila->neto);
        }

        // Las que no tienen ninguna asignación no vuelven de la consulta.
        foreach ($installmentIds as $id) {
            $porCuota[$id] ??= '0.00';
        }

        return $porCuota;
    }

    /**
     * Lo que le falta para estar completa.
     *
     * Nunca negativo: el excedente de redondeo en efectivo puede superar
     * el esperado, y «le faltan menos cero pesos» no es una respuesta
     * útil para nadie.
     *
     * @return numeric-string
     */
    public function remaining(BeneficiaryInstallment $installment): string
    {
        $falta = Decimal::sub($installment->importeEsperado(), $this->allocated($installment));

        return Decimal::isNegative($falta) ? '0.00' : $falta;
    }

    /**
     * Lo imputado de más, cuando el importe de la cuota bajó.
     *
     * `remaining()` recorta los negativos a cero —y tiene que seguir
     * haciéndolo, porque es lo que se cobra por mostrador y un importe
     * negativo ahí no significa nada—. Pero ese recorte **escondía** el
     * excedente: la cuota decía «financiada por completo» con plata de
     * más adentro.
     *
     * Esto lo saca a la luz. No es un error del sistema: es un estado
     * legítimo y transitorio, que se resuelve devolviendo la diferencia
     * al pozo de no identificados con `UnallocateFunds`.
     *
     * @return numeric-string
     */
    public function overAllocated(BeneficiaryInstallment $installment): string
    {
        $sobra = Decimal::sub($this->allocated($installment), $installment->importeEsperado());

        return Decimal::isNegative($sobra) ? '0.00' : $sobra;
    }

    /**
     * Si la cuota está completamente financiada.
     *
     * Es la condición que habilita el recibo de ingreso y, con él, la
     * Orden de Pago. **Se deriva, nunca se marca a mano.**
     */
    public function isFullyFunded(BeneficiaryInstallment $installment): bool
    {
        return Decimal::equals($this->remaining($installment), '0');
    }

    /**
     * El medio con el que se está financiando, si ya se fijó.
     *
     * Lo fija la primera recepción (invariante 4) y de ahí en más es el
     * que va impreso en el recibo.
     */
    public function medium(BeneficiaryInstallment $installment): ?PaymentMedium
    {
        $medio = FundingAllocation::query()
            ->withRemainingBalance()
            ->where('beneficiary_installment_id', $installment->id)
            ->join('fund_receipts', 'fund_receipts.id', '=', 'funding_allocations.fund_receipt_id')
            ->value('fund_receipts.medium');

        return $medio === null ? null : PaymentMedium::from((string) $medio);
    }

    /**
     * El medio que hoy describe a la cuota.
     *
     * Con plata adentro es el **real** —el que fijo la primera recepcion y
     * el que va impreso en el recibo—; sin plata, el previsto, que ahi es
     * lo unico que hay.
     *
     * Nunca al reves, y esa es toda la regla. `expected_medium` es una
     * expectativa del expediente: se edita, y puede quedar contradiciendo
     * a un hecho ya asentado. Todo lo que decide algo —si el boton de
     * cobrar aparece, que dice la vista previa del papel— tiene que
     * preguntar por esto y no por la columna.
     */
    public function effectiveMedium(BeneficiaryInstallment $installment): PaymentMedium
    {
        return $this->medium($installment)
            ?? PaymentMedium::from($installment->expected_medium->value);
    }

    /**
     * El medio fijado para muchas cuotas, en una sola consulta.
     *
     * @param  list<int>  $installmentIds
     * @return array<int, PaymentMedium|null>
     */
    public function mediumForMany(array $installmentIds): array
    {
        if ($installmentIds === []) {
            return [];
        }

        $porCuota = array_fill_keys($installmentIds, null);
        $filas = DB::table('funding_allocations')
            ->where('allocation_kind', '!=', AllocationKind::Reversal->value)
            ->whereRaw(
                'funding_allocations.amount > COALESCE(('
                .'SELECT SUM(reversions.amount) FROM funding_allocations AS reversions '
                .'WHERE reversions.reversal_of_id = funding_allocations.id'
                .'), 0)',
            )
            ->whereIn('beneficiary_installment_id', $installmentIds)
            ->join('fund_receipts', 'fund_receipts.id', '=', 'funding_allocations.fund_receipt_id')
            ->orderBy('funding_allocations.id')
            ->get([
                'funding_allocations.beneficiary_installment_id',
                'fund_receipts.medium',
            ]);

        foreach ($filas as $fila) {
            $id = (int) $fila->beneficiary_installment_id;
            $porCuota[$id] ??= PaymentMedium::from((string) $fila->medium);
        }

        return $porCuota;
    }

    /**
     * Lo que a una recepción le queda sin asignar.
     *
     * Su saldo vivo en `UNASSIGNED_FUNDS`: lo que entró y todavía no se
     * sabe de quién es.
     *
     * @return numeric-string
     */
    public function unallocated(FundReceipt $receipt): string
    {
        return Decimal::sub($receipt->amount, $this->allocatedFrom($receipt));
    }

    /**
     * Lo que de una recepción ya tiene dueño.
     *
     * @return numeric-string
     */
    public function allocatedFrom(FundReceipt $receipt): string
    {
        return $this->netSum(
            FundingAllocation::query()->where('fund_receipt_id', $receipt->id)
        );
    }

    /**
     * Igual, pero serializando a quien esté asignando la misma recepción.
     *
     * Dos operadores repartiendo el mismo dinero al mismo tiempo leerían
     * ambos el saldo entero y ambos creerían tener lugar. El bloqueo va
     * sobre la recepción porque las filas que compiten todavía no
     * existen: no hay nada que bloquear en ellas.
     *
     * Solo tiene sentido dentro de una transacción.
     *
     * @return numeric-string
     */
    public function unallocatedForUpdate(FundReceipt $receipt): string
    {
        DB::table('fund_receipts')
            ->where('id', $receipt->id)
            ->lockForUpdate()
            ->value('id');

        return $this->unallocated($receipt);
    }

    /**
     * @param  Builder<FundingAllocation>  $query
     * @return numeric-string
     */
    private function netSum(Builder $query): string
    {
        $neto = $query
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN allocation_kind = ? THEN -amount ELSE amount END), 0) AS neto',
                [AllocationKind::Reversal->value],
            )
            ->value('neto');

        return Decimal::scale((string) ($neto ?? '0'));
    }
}
