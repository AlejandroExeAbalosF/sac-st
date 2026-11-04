<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Models;

use App\Modules\Aranceles\Enums\FeeCalculationMode;
use Illuminate\Database\Eloquent\Model;

/**
 * Un renglón de un cobro: la copia de lo que valía ese día, lo calculado y lo
 * cobrado. No se edita.
 *
 * @property int $id
 * @property int $fee_charge_id
 * @property int $line_number
 * @property int $fee_type_id
 * @property int $fee_type_price_id
 * @property int $code_snapshot
 * @property string $name_snapshot
 * @property FeeCalculationMode $calculation_mode_snapshot
 * @property string|null $unit_label_snapshot
 * @property int|null $quantity
 * @property numeric-string|null $unit_price
 * @property numeric-string|null $rate
 * @property numeric-string|null $base_amount
 * @property numeric-string $calculated_amount
 * @property numeric-string $original_amount
 * @property string|null $change_reason_code
 * @property string|null $change_reason
 */
class FeeChargeItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'fee_charge_id',
        'line_number',
        'fee_type_id',
        'fee_type_price_id',
        'code_snapshot',
        'name_snapshot',
        'calculation_mode_snapshot',
        'unit_label_snapshot',
        'quantity',
        'unit_price',
        'rate',
        'base_amount',
        'calculated_amount',
        'original_amount',
        'change_reason_code',
        'change_reason',
    ];

    protected function casts(): array
    {
        return [
            'calculation_mode_snapshot' => FeeCalculationMode::class,
            'unit_price' => 'decimal:2',
            'rate' => 'decimal:4',
            'base_amount' => 'decimal:2',
            'calculated_amount' => 'decimal:2',
            'original_amount' => 'decimal:2',
        ];
    }
}
