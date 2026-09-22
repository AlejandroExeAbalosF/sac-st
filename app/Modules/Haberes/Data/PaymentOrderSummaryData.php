<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Haberes\Enums\PaymentOrderStatus;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Support\BusinessDate;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** La Orden de Pago vigente de una cuota, vista desde su tarjeta. */
#[TypeScript]
final class PaymentOrderSummaryData extends Data
{
    /**
     * Las relaciones que `fromModel()` lee.
     *
     * Viven con el DTO porque el que las necesita es él: con
     * `preventLazyLoading` encendido, una relación sin cargar no es una
     * consulta de más, es un 500.
     *
     * @var list<string>
     */
    public const RELATIONS = ['pase', 'organismBankAccount:id,label,bank_name,account_number'];

    public function __construct(
        public int $id,
        /** El número pelado, que es el que va impreso: `3582`. */
        public int $number,
        /** El de la serie, para buscarlo: `0030/00003582`. */
        public string $formattedNumber,
        public string $orderDate,
        public PaymentOrderStatus $status,
        /** @var numeric-string */
        public string $amount,
        public ?string $beneficiaryCbu,
        public ?string $cbuFolio,
        public ?string $organismAccountLabel,
        public string $incomeReceiptNumber,
        public ?string $notes,
        /** La nota que la acompaña. Nace con ella, así que casi nunca falta. */
        public ?PaseSummaryData $pase,
        public ?string $voidReason,
        public ?string $voidedAt,
    ) {}

    public static function fromModel(PaymentOrder $order): self
    {
        return new self(
            id: $order->id,
            number: $order->number,
            formattedNumber: $order->formatted_number,
            orderDate: $order->order_date->format('Y-m-d'),
            status: $order->status,
            amount: $order->amount,
            beneficiaryCbu: $order->beneficiary_cbu_snapshot,
            cbuFolio: $order->cbu_folio_snapshot,
            organismAccountLabel: $order->organismBankAccount?->label,
            incomeReceiptNumber: $order->income_receipt_number_snapshot,
            notes: $order->notes,
            pase: $order->pase === null ? null : PaseSummaryData::fromModel($order->pase),
            voidReason: $order->rejection_or_void_reason,
            voidedAt: $order->voided_at === null ? null : BusinessDate::fromInstant($order->voided_at)->toDateString(),
        );
    }
}
