<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Support;

use App\Models\User;
use App\Modules\Aranceles\Data\FeeChargeFundingData;
use App\Modules\Aranceles\Data\FeeChargeLineData;
use App\Modules\Aranceles\Data\FeeChargeListItemData;
use App\Modules\Aranceles\Enums\FeeReasonCode;
use App\Modules\Aranceles\Models\FeeCharge;
use App\Modules\Aranceles\Models\FeeChargeFunding;
use App\Modules\Aranceles\Models\FeeChargeItem;
use App\Modules\Aranceles\Models\FeeChargeReceipt;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Models\Attachment;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;

/** Un cobro como lo muestran las pantallas: el listado, los pendientes y su corrección. */
final class FeeChargeRows
{
    public function __construct(private readonly FeeChargeFunds $fondos) {}

    public function row(FeeCharge $cobro): FeeChargeListItemData
    {
        $vigente = $cobro->chargeReceipts->first(fn (FeeChargeReceipt $c): bool => $c->receipt->status === ReceiptStatus::Issued);
        $ultimo = $vigente ?? $cobro->chargeReceipts->first();
        $papel = $ultimo?->snapshot;
        $importes = DB::table('fee_charge_item_amounts')->where('fee_charge_id', $cobro->id)->pluck('amount', 'fee_charge_item_id');

        $vinculos = FeeChargeFunding::query()
            ->where('fee_charge_id', $cobro->id)
            ->whereNull('released_at')
            ->with('fundReceipt')
            ->orderBy('id')
            ->get();
        $total = Decimal::scale((string) $cobro->balance?->total);
        $vinculado = Decimal::scale((string) $cobro->balance?->funded);

        return new FeeChargeListItemData(
            id: $cobro->id,
            payerName: $papel['payerName'] ?? $cobro->payer_name,
            // Un CUIT quitado en el reemplazo es nulo en el papel: no vuelve el original.
            payerCuit: $papel === null ? $cobro->payer_cuit : ($papel['payerCuit'] ?? null),
            medium: $cobro->medium,
            status: $cobro->status,
            requestedOn: $cobro->requested_on->toDateString(),
            paidOn: $cobro->paid_on?->toDateString(),
            pricingDate: $cobro->pricing_date->toDateString(),
            total: $total,
            funded: $vinculado,
            missing: Decimal::sub($total, $vinculado),
            lines: array_values($cobro->items->map(fn (FeeChargeItem $i): FeeChargeLineData => new FeeChargeLineData(
                itemId: $i->id,
                feeTypeId: $i->fee_type_id,
                code: $i->code_snapshot,
                name: $i->name_snapshot,
                quantity: $i->quantity,
                baseAmount: $i->base_amount,
                calculatedAmount: $i->calculated_amount,
                amount: Decimal::scale((string) ($importes[$i->id] ?? $i->original_amount)),
                reasonCode: $i->change_reason_code,
                reason: $i->change_reason,
            ))->all()),
            fundings: array_values($vinculos->map(fn (FeeChargeFunding $v): FeeChargeFundingData => new FeeChargeFundingData(
                amount: Decimal::scale((string) $v->amount),
                receivedOn: $v->fundReceipt->received_date->toDateString(),
            ))->all()),
            receiptId: $ultimo?->receipt_id,
            receiptNumber: $ultimo?->receipt->formatted_number,
            talonarioNumber: $ultimo?->receipt->talonario_number,
            talonarioPhotoId: Attachment::query()
                ->where('subject_type', AttachmentSubject::Receipt)
                ->whereIn('subject_id', $cobro->chargeReceipts->pluck('receipt_id'))
                ->where('document_type', 'talonario_photo')
                ->value('id'),
            voidReason: $this->motivoDeAnulacion($cobro),
            deposited: $vinculos->contains(fn (FeeChargeFunding $v): bool => $this->fondos->isDeposited($v->fund_receipt_id)),
        );
    }

    /**
     * Quienes pueden firmar el recibo, con su cargo. Opcional: sin firmante el
     * pie queda para firmar y sellar a mano (regla A92).
     *
     * @return list<array{id: int, name: string, position: string|null}>
     */
    public function signers(): array
    {
        return array_values(User::query()
            ->where('is_active', true)
            ->whereNotNull('position')
            ->orderBy('name')
            ->get(['id', 'name', 'position'])
            ->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name, 'position' => $u->position])
            ->all());
    }

    /** Se lee junto: «Error de carga: el importe tenía un cero de más». */
    private function motivoDeAnulacion(FeeCharge $cobro): ?string
    {
        if ($cobro->void_reason_code === null) {
            return null;
        }

        $codigo = FeeReasonCode::tryFrom($cobro->void_reason_code)?->label() ?? $cobro->void_reason_code;

        return $cobro->void_reason === null ? $codigo : $codigo.': '.$cobro->void_reason;
    }
}
