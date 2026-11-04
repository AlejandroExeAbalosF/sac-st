<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Models;

use App\Modules\Aranceles\Enums\FeeCalculationMode;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un tipo de arancel del catálogo.
 *
 * Se edita, y lo emitido no cambia: cada renglón guarda su copia. En uso no se
 * borra, se desactiva (regla A11).
 *
 * @property int $id
 * @property int $code
 * @property string|null $decree_letter
 * @property string $name
 * @property FeeCalculationMode $calculation_mode
 * @property string|null $unit_label
 * @property bool $is_active
 */
class FeeType extends Model
{
    protected $fillable = [
        'code',
        'decree_letter',
        'name',
        'calculation_mode',
        'unit_label',
        'is_active',
    ];

    /** @return HasMany<FeeTypePrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(FeeTypePrice::class);
    }

    /** El precio que rige ese día: el último vigente que ya empezó. */
    public function priceOn(CarbonInterface $date): ?FeeTypePrice
    {
        return $this->prices()
            ->whereNull('voided_at')
            ->whereDate('valid_from', '<=', $date->toDateString())
            ->orderByDesc('valid_from')
            ->first();
    }

    protected function casts(): array
    {
        return [
            'code' => 'integer',
            'calculation_mode' => FeeCalculationMode::class,
            'is_active' => 'boolean',
        ];
    }
}
