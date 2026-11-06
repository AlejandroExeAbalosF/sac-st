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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;

/** Un cobro como lo muestran las pantallas: el listado, los pendientes y su corrección. */
final class FeeChargeRows
{
    public function __construct(private readonly FeeChargeFunds $fondos) {}

    public function row(FeeCharge $cobro): FeeChargeListItemData
    {
        return $this->rows(new Collection([$cobro]))[$cobro->id];
    }

    /**
     * Una página del listado: las filas se arman juntas, no de a una.
     *
     * @param  LengthAwarePaginator<int, FeeCharge>  $pagina
     * @return LengthAwarePaginator<int, FeeChargeListItemData>
     */
    public function page(LengthAwarePaginator $pagina): LengthAwarePaginator
    {
        $filas = $this->rows(new Collection($pagina->items()));

        return $pagina->through(fn (FeeCharge $cobro): FeeChargeListItemData => $filas[$cobro->id]);
    }

    /**
     * Las filas de varios cobros, por el id de cada uno, con una consulta por
     * dato y no una por cobro. Los cobros llegan con `items`, `balance` y
     * `chargeReceipts.receipt` cargados.
     *
     * @param  Collection<int, FeeCharge>  $cobros
     * @return array<int, FeeChargeListItemData>
     */
    public function rows(Collection $cobros): array
    {
        $ids = $cobros->modelKeys();
        $importes = DB::table('fee_charge_item_amounts')->whereIn('fee_charge_id', $ids)->pluck('amount', 'fee_charge_item_id');
        $vinculos = FeeChargeFunding::query()
            ->whereIn('fee_charge_id', $ids)
            ->whereNull('released_at')
            ->with('fundReceipt')
            ->orderBy('id')
            ->get();
        $depositadas = $this->fondos->depositedAmong(array_values($vinculos->pluck('fund_receipt_id')->unique()->all()));
        // Por todos los recibos del cobro: el reemplazo emite otro, sin talonario.
        $fotos = Attachment::query()
            ->where('subject_type', AttachmentSubject::Receipt)
            ->whereIn('subject_id', $cobros->flatMap(fn (FeeCharge $c) => $c->chargeReceipts->pluck('receipt_id')))
            ->where('document_type', 'talonario_photo')
            ->orderBy('id')
            ->pluck('id', 'subject_id');

        $filas = [];

        foreach ($cobros as $cobro) {
            $filas[$cobro->id] = $this->build(
                $cobro,
                $importes,
                $vinculos->where('fee_charge_id', $cobro->id)->values(),
                $depositadas,
                $cobro->chargeReceipts->map(fn (FeeChargeReceipt $c): ?int => $fotos[$c->receipt_id] ?? null)->filter()->first(),
            );
        }

        return $filas;
    }

    /**
     * @param  BaseCollection<array-key, mixed>  $importes
     * @param  Collection<int, FeeChargeFunding>  $vinculos
     * @param  list<int>  $depositadas
     */
    private function build(FeeCharge $cobro, BaseCollection $importes, Collection $vinculos, array $depositadas, ?int $foto): FeeChargeListItemData
    {
        $vigente = $cobro->chargeReceipts->first(fn (FeeChargeReceipt $c): bool => $c->receipt->status === ReceiptStatus::Issued);
        $ultimo = $vigente ?? $cobro->chargeReceipts->first();
        $papel = $ultimo?->snapshot;
        $total = Decimal::scale((string) $cobro->balance?->total);
        $vinculado = Decimal::scale((string) $cobro->balance?->funded);

        return new FeeChargeListItemData(
            id: $cobro->id,
            payerName: $papel['payerName'] ?? $cobro->payer_name,
            // Un documento quitado en el reemplazo es nulo en el papel: no vuelve el original.
            payerDocument: $papel === null ? $cobro->payer_document : ($papel['payerDocument'] ?? null),
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
            talonarioPhotoId: $foto,
            voidReason: $this->motivoDeAnulacion($cobro),
            deposited: $vinculos->contains(fn (FeeChargeFunding $v): bool => in_array($v->fund_receipt_id, $depositadas, true)),
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
