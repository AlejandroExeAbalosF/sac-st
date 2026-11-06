<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Actions;

use App\Modules\Aranceles\Enums\FeeChargeStatus;
use App\Modules\Aranceles\Models\FeeCharge;
use App\Modules\Aranceles\Models\FeeChargeBalance;
use App\Modules\Aranceles\Models\FeeChargeFunding;
use App\Modules\Aranceles\Models\FeeChargeItem;
use App\Modules\Aranceles\Models\FeeChargeReceipt;
use App\Modules\Aranceles\Support\FeeChargeFunds;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Un cobro pendiente que alcanzó su total: se aplica el dinero y se emite un solo
 * recibo con todos sus hechos (regla A52, A53).
 *
 * Corre dentro de la transacción de quien lo completó, con el cobro bloqueado.
 */
final class CompleteFeeCharge
{
    public function __construct(
        private readonly FeeChargeFunds $fondos,
        private readonly IssueFeeReceipt $emitir,
        private readonly RecordAuditEvent $auditar,
    ) {}

    public function handle(FeeCharge $cobro, ?int $signedById, ?int $actorId): FeeChargeReceipt
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Un cobro se completa en la transacción que lo identificó.');
        }

        $hoy = BusinessDate::today();
        $eventos = [];
        $recepciones = [];

        foreach (FeeChargeFunding::query()->where('fee_charge_id', $cobro->id)->whereNull('released_at')->orderBy('id')->get() as $vinculo) {
            $aplicacion = $this->fondos->applyLinked($vinculo, $hoy, $actorId);
            $recepciones[] = $vinculo->fund_receipt_id;
            $eventos[] = (int) FundReceipt::query()->whereKey($vinculo->fund_receipt_id)->value('financial_event_id');
            $eventos[] = $aplicacion->id;
        }

        $cobro->forceFill(['status' => FeeChargeStatus::Paid, 'paid_on' => $hoy])->save();

        $total = Decimal::scale((string) FeeChargeBalance::query()->whereKey($cobro->id)->value('total'));
        $comprobante = $this->emitir->handle(
            $cobro,
            $this->papel($cobro, $recepciones),
            $total,
            $hoy,
            $eventos,
            null,
            $signedById,
            $actorId,
        );

        $this->auditar->handle('arancel.cobrado', $cobro, metadata: [
            'medio' => $cobro->medium->label(),
            'amount' => $total,
            'recibo' => $comprobante->receipt()->value('formatted_number'),
        ], actorId: $actorId);

        return $comprobante;
    }

    /**
     * Lo que va a decir el papel, con el importe vigente de cada renglón.
     *
     * @param  list<int>  $recepciones
     * @return array{payerName: string, payerDocument: string|null, lines: list<array{code: int, name: string, unitLabel: string|null, quantity: int|null, unitPrice: string|null, rate: string|null, baseAmount: string|null, amount: string}>, bankAccountNumber: string|null}
     */
    private function papel(FeeCharge $cobro, array $recepciones): array
    {
        $vigentes = DB::table('fee_charge_item_amounts')->where('fee_charge_id', $cobro->id)->pluck('amount', 'fee_charge_item_id');

        return [
            'payerName' => $cobro->payer_name,
            'payerDocument' => $cobro->payer_document,
            'lines' => array_values($cobro->items()->get()->map(fn (FeeChargeItem $i): array => [
                'code' => $i->code_snapshot,
                'name' => $i->name_snapshot,
                'unitLabel' => $i->unit_label_snapshot,
                'quantity' => $i->quantity,
                'unitPrice' => $i->unit_price,
                'rate' => $i->rate,
                'baseAmount' => $i->base_amount,
                'amount' => Decimal::scale((string) $vigentes[$i->id]),
            ])->all()),
            'bankAccountNumber' => $this->fondos->accountNumbersOf($recepciones),
        ];
    }
}
