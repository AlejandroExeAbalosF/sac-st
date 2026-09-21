<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Shared\Models\CashBox;
use Illuminate\Validation\ValidationException;

/**
 * La caja de la que sale el dinero de una cuota.
 *
 * Es aquella en la que entró: el egreso acredita la misma cuenta que el
 * ingreso debitó, y si no fuera la misma caja el arqueo de una quedaría
 * corto y el de la otra largo. Se lee de las recepciones que financiaron
 * la cuota en vez de asumirse, aunque hoy sea siempre la de Haberes.
 *
 * **Vale igual para el mostrador que para la transferencia.** Es la razón
 * de que viva acá y no dentro de un Action: el egreso bancario no toca el
 * cajón, pero sí la columna «DEPOSITOS DIRECTOS» de la planilla, que se
 * calcula sobre `BANK_ACCOUNT` filtrando por caja. Una línea sin caja no
 * entra en ningún cierre y el saldo de esa columna solo sabría subir.
 */
final class InstallmentCashBox
{
    /** @throws ValidationException */
    public function for(BeneficiaryInstallment $installment): int
    {
        $caja = FundReceipt::query()
            ->whereIn('id', FundingAllocation::query()
                ->live()
                ->where('beneficiary_installment_id', $installment->id)
                ->select('fund_receipt_id'))
            ->whereNotNull('cash_box_id')
            ->value('cash_box_id');

        if ($caja !== null) {
            return (int) $caja;
        }

        $porDefecto = CashBox::query()->where('code', CashBox::HABERES)->value('id');

        if ($porDefecto === null) {
            throw ValidationException::withMessages([
                'installmentId' => 'No existe la caja de Haberes: hay que sembrarla antes de pagar.',
            ]);
        }

        return (int) $porDefecto;
    }
}
