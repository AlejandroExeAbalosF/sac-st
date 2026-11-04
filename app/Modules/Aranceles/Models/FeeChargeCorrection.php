<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Qué cobro corrigió a cuál.
 *
 * @property int $id
 * @property int $corrected_charge_id
 * @property int $new_charge_id
 * @property string $reason_code
 * @property string|null $reason
 * @property int|null $corrected_by
 */
class FeeChargeCorrection extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'corrected_charge_id',
        'new_charge_id',
        'reason_code',
        'reason',
        'corrected_by',
        'corrected_at',
    ];
}
