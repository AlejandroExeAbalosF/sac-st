<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Data;

use App\Modules\Aranceles\Enums\FeeCalculationMode;
use App\Modules\Aranceles\Models\FeeType;
use App\Modules\Aranceles\Models\FeeTypePrice;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Un tipo de arancel con su precio de hoy y su historial. */
#[TypeScript]
final class FeeTypeData extends Data
{
    /**
     * @param  list<FeeTypePriceData>  $prices  Del más nuevo al más viejo.
     */
    public function __construct(
        public int $id,
        public int $code,
        public ?string $decreeLetter,
        public string $name,
        public FeeCalculationMode $calculationMode,
        public ?string $unitLabel,
        public bool $isActive,
        public ?FeeTypePriceData $currentPrice,
        public array $prices,
    ) {}

    /** Espera `prices` cargados, del más nuevo al más viejo. */
    public static function fromModel(FeeType $type, CarbonInterface $today): self
    {
        $hoy = $today->toDateString();

        $vigente = $type->prices->first(
            fn (FeeTypePrice $p): bool => $p->voided_at === null && $p->valid_from->toDateString() <= $hoy,
        );

        $precios = array_values($type->prices->map(
            fn (FeeTypePrice $p): FeeTypePriceData => FeeTypePriceData::fromModel($p, $vigente?->id, $hoy),
        )->all());

        return new self(
            id: $type->id,
            code: $type->code,
            decreeLetter: $type->decree_letter,
            name: $type->name,
            calculationMode: $type->calculation_mode,
            unitLabel: $type->unit_label,
            isActive: $type->is_active,
            currentPrice: $vigente === null ? null : FeeTypePriceData::fromModel($vigente, $vigente->id, $hoy),
            prices: $precios,
        );
    }
}
