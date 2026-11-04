<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que vale un tipo de arancel desde una fecha.
 *
 * No se edita ni se borra: un cambio es otro precio, y uno mal cargado se anula
 * con su motivo (regla A12). La base lo impone.
 *
 * @property int $id
 * @property int $fee_type_id
 * @property CarbonImmutable $valid_from
 * @property numeric-string|null $unit_price
 * @property numeric-string|null $rate
 * @property string|null $legal_reference
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 */
class FeeTypePrice extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'fee_type_id',
        'valid_from',
        'unit_price',
        'rate',
        'legal_reference',
        'created_by',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    /** @return BelongsTo<FeeType, $this> */
    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return [
            'valid_from' => 'immutable_date',
            'unit_price' => 'decimal:2',
            'rate' => 'decimal:4',
            'created_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }
}
