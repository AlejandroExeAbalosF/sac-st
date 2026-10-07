<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Haberes\Models\LegacySettlement;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Support\BusinessDate;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Cómo se pagó una cuota antes de la apertura: cuándo y con qué.
 */
#[TypeScript]
final class LegacySettlementData extends Data
{
    public function __construct(
        public int $id,
        /** @var numeric-string */
        public string $amount,
        public string $paidOn,
        public PaymentMedium $paymentMedium,
        public ?string $notes,
        public ?string $recordedBy,
        public string $recordedAt,
    ) {}

    public static function fromModel(LegacySettlement $registro): self
    {
        return new self(
            id: $registro->id,
            amount: $registro->amount,
            paidOn: $registro->paid_on->format('Y-m-d'),
            paymentMedium: $registro->payment_medium,
            notes: $registro->notes,
            recordedBy: $registro->recorder?->name,
            recordedAt: BusinessDate::fromInstant($registro->recorded_at)->toDateString(),
        );
    }
}
