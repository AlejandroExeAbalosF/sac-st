<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Data;

use App\Modules\Aranceles\Models\FeeTypePrice;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Un precio del historial de un tipo de arancel. */
#[TypeScript]
final class FeeTypePriceData extends Data
{
    public function __construct(
        public int $id,
        public string $validFrom,
        public ?string $unitPrice,
        public ?string $rate,
        public ?string $legalReference,
        public ?string $createdBy,
        public string $createdAt,
        /** `current`, `scheduled`, `past` o `voided`. */
        public string $status,
        public ?string $voidReason,
    ) {}

    public static function fromModel(FeeTypePrice $price, ?int $currentPriceId, string $today): self
    {
        return new self(
            id: $price->id,
            validFrom: $price->valid_from->toDateString(),
            unitPrice: $price->unit_price,
            rate: $price->rate,
            legalReference: $price->legal_reference,
            createdBy: $price->creator?->name,
            createdAt: $price->created_at->toIso8601String(),
            status: match (true) {
                $price->voided_at !== null => 'voided',
                $price->id === $currentPriceId => 'current',
                $price->valid_from->toDateString() > $today => 'scheduled',
                default => 'past',
            },
            voidReason: $price->void_reason,
        );
    }
}
