<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un cambio de importe de un renglón de un cobro pendiente. Solo se agrega: el
 * importe original se conserva.
 *
 * @property int $id
 * @property int $fee_charge_item_id
 * @property numeric-string $previous_amount
 * @property numeric-string $new_amount
 * @property string $change_reason_code
 * @property string|null $change_reason
 * @property int|null $adjusted_by
 */
class FeeChargeItemAdjustment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'fee_charge_item_id',
        'previous_amount',
        'new_amount',
        'change_reason_code',
        'change_reason',
        'adjusted_by',
    ];

    protected function casts(): array
    {
        return ['previous_amount' => 'decimal:2', 'new_amount' => 'decimal:2'];
    }
}
