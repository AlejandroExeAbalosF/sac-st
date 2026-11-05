<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FundReceiptOrigin;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\CashBalance;
use App\Support\Money\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * El efectivo de una caja que todavía se puede llevar al banco, por recepción.
 *
 * Es lo recibido o traído en la apertura, vigente y sin un traslado que ya lo
 * lleve, hasta la fecha que se mira: el banco no recibe lo que la caja todavía
 * no cobró. Se reparte de la recepción más antigua a la más nueva.
 *
 * @phpstan-type Share array{receipt: FundReceipt, amount: numeric-string}
 */
final class DepositableCash
{
    private const VIAJADO = '(SELECT coalesce(sum(i.amount), 0)
        FROM cash_to_bank_transfer_items i
        JOIN cash_to_bank_transfers t ON t.id = i.cash_to_bank_transfer_id
        WHERE i.fund_receipt_id = fund_receipts.id AND t.status <> \'cancelled\')';

    public function __construct(private readonly CashBalance $saldos) {}

    /**
     * Toma las recepciones con saldo, por `id`. Se hace antes de leer los saldos:
     * si otro traspaso las tenía, al seguir se lee lo que dejó.
     */
    public function lock(int $cashBoxId, Currency $currency, CarbonInterface $upTo): void
    {
        $this->candidatas($cashBoxId, $currency, $upTo)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');
    }

    /**
     * Lo disponible, de la recepción más antigua a la más nueva.
     *
     * @return list<Share>
     */
    public function available(int $cashBoxId, Currency $currency, CarbonInterface $upTo): array
    {
        $filas = $this->candidatas($cashBoxId, $currency, $upTo)
            ->select('fund_receipts.*')
            ->selectRaw(self::VIAJADO.' AS viajado')
            ->orderBy('received_date')
            ->orderBy('id')
            ->get();

        $disponibles = [];

        foreach ($filas as $recepcion) {
            $disponibles[] = [
                'receipt' => $recepcion,
                'amount' => Decimal::sub($recepcion->amount, (string) $recepcion->getAttribute('viajado')),
            ];
        }

        return $disponibles;
    }

    /**
     * @param  list<Share>  $disponibles
     * @return numeric-string
     */
    public function total(array $disponibles): string
    {
        $total = '0.00';

        foreach ($disponibles as $parte) {
            $total = Decimal::add($total, $parte['amount']);
        }

        return $total;
    }

    /**
     * Lo máximo que puede salir en esa fecha: lo que las recepciones respaldan,
     * sin dejar el libro en negativo ese día ni después. Un faltante ajustado baja
     * el libro y no las recepciones.
     *
     * @param  list<Share>  $disponibles
     * @return numeric-string
     */
    public function maximum(int $cashBoxId, Currency $currency, CarbonInterface $fecha, array $disponibles): string
    {
        $respaldado = $this->total($disponibles);
        $libro = $this->saldos->lowestSince(LedgerAccount::CashOnHand, $cashBoxId, $currency, $fecha)['balance'];

        if (Decimal::isNegative($libro)) {
            return '0.00';
        }

        return Decimal::isNegative(Decimal::sub($libro, $respaldado)) ? $libro : $respaldado;
    }

    /**
     * Reparte el importe desde la recepción más antigua. Lo que no alcance a
     * cubrirse lo controla quien llama: `total()` dice cuánto hay.
     *
     * @param  list<Share>  $disponibles
     * @return list<Share>
     */
    public function distribute(array $disponibles, string $importe): array
    {
        $falta = Decimal::scale($importe);
        $reparto = [];

        foreach ($disponibles as $parte) {
            if (! Decimal::isNegative(Decimal::sub('0', $falta))) {
                break;
            }

            $toma = Decimal::isNegative(Decimal::sub($parte['amount'], $falta)) ? $parte['amount'] : $falta;
            $reparto[] = ['receipt' => $parte['receipt'], 'amount' => $toma];
            $falta = Decimal::sub($falta, $toma);
        }

        return $reparto;
    }

    /** @return Builder<FundReceipt> */
    private function candidatas(int $cashBoxId, Currency $currency, CarbonInterface $upTo): Builder
    {
        return FundReceipt::query()
            ->where('cash_box_id', $cashBoxId)
            ->where('currency', $currency->value)
            ->where('medium', PaymentMedium::Cash->value)
            ->whereIn('origin', [FundReceiptOrigin::Received->value, FundReceiptOrigin::Opening->value])
            ->whereNull('reversal_event_id')
            ->whereDate('received_date', '<=', $upTo)
            ->whereRaw('amount > '.self::VIAJADO);
    }
}
