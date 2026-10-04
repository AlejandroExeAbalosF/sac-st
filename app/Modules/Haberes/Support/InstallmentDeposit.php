<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Support\Money\Decimal;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lo que viaja al banco cuando se deposita lo que una cuota tiene en la caja.
 *
 * Es todo lo que la cuota tiene en pie —efectivo y cheques—, y además **el
 * resto de cada cheque**. Un cheque se deposita como llegó a la Secretaría:
 * entero. Si la cuota tiene solo una parte —bajó y se liberó el excedente,
 * o lo comparte con otra cuota—, el papel viaja completo igual, y con él
 * la parte de la otra cuota y la que todavía no tiene dueño.
 *
 * El efectivo no arrastra nada: se puede contar y llevar solo lo de esta
 * cuota.
 *
 * La pantalla del traslado y el Action leen lo mismo, así que el importe
 * que se muestra es exactamente el que se asienta.
 */
final class InstallmentDeposit
{
    public function __construct(private readonly InstallmentFunding $financiacion) {}

    /** @return list<DepositItem> */
    public function items(BeneficiaryInstallment $installment): array
    {
        $items = [];
        $cheques = [];

        foreach ($this->conSaldo()->where('beneficiary_installment_id', $installment->id)->get() as $propia) {
            $items[] = $this->item($propia, true);

            if ($propia->fundReceipt->medium === PaymentMedium::Cheque) {
                $cheques[$propia->fund_receipt_id] = $propia->fundReceipt;
            }
        }

        foreach ($cheques as $cheque) {
            $ajenas = $this->conSaldo()
                ->where('fund_receipt_id', $cheque->id)
                ->where('beneficiary_installment_id', '!=', $installment->id)
                ->get();

            foreach ($ajenas as $ajena) {
                $items[] = $this->item($ajena, false);
            }

            // Lo que del cheque todavía no tiene dueño viaja sin asignación.
            $sinDueño = $this->financiacion->unallocated($cheque);

            if (! Decimal::isNegative($sinDueño) && ! Decimal::equals($sinDueño, '0')) {
                $items[] = new DepositItem($cheque, null, Decimal::scale($sinDueño), false);
            }
        }

        return $items;
    }

    /**
     * @param  list<DepositItem>  $items
     * @return numeric-string
     */
    public static function total(array $items): string
    {
        return array_reduce(
            $items,
            static fn (string $total, DepositItem $item): string => Decimal::add($total, $item->amount),
            '0.00',
        );
    }

    /**
     * Lo que viaja sin ser de la cuota: el resto de sus cheques.
     *
     * @param  list<DepositItem>  $items
     * @return numeric-string
     */
    public static function carried(array $items): string
    {
        return self::total(array_values(array_filter(
            $items,
            static fn (DepositItem $item): bool => ! $item->ownedByInstallment,
        )));
    }

    /** @return Builder<FundingAllocation> */
    private function conSaldo(): Builder
    {
        return FundingAllocation::query()
            ->withRemainingBalance()
            ->with('fundReceipt')
            ->select('funding_allocations.*')
            ->selectRaw(
                'funding_allocations.amount - COALESCE(('
                .'SELECT SUM(reversions.amount) FROM funding_allocations AS reversions '
                .'WHERE reversions.reversal_of_id = funding_allocations.id'
                .'), 0) AS remaining',
            )
            ->orderBy('funding_allocations.id');
    }

    private function item(FundingAllocation $asignacion, bool $propia): DepositItem
    {
        return new DepositItem(
            $asignacion->fundReceipt,
            $asignacion->id,
            Decimal::scale((string) $asignacion->getAttribute('remaining')),
            $propia,
        );
    }
}
