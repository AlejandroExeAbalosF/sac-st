<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Models;

use App\Models\User;
use App\Modules\Aranceles\Enums\FeeChargeMedium;
use App\Modules\Aranceles\Enums\FeeChargeStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Un pago de un arancel: quién paga, sus renglones, el dinero que lo respalda y
 * su recibo. No guarda total: es la suma de los renglones.
 *
 * Quién paga es lo que se cargó al crearlo. Lo vigente es lo que dice el último
 * recibo, si se reemplazó para corregirlo.
 *
 * @property int $id
 * @property string $payer_name
 * @property string|null $payer_cuit
 * @property FeeChargeMedium $medium
 * @property FeeChargeStatus $status
 * @property CarbonImmutable $requested_on
 * @property CarbonImmutable $pricing_date
 * @property CarbonImmutable|null $paid_on
 * @property string $idempotency_key
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason_code
 * @property string|null $void_reason
 */
class FeeCharge extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'payer_name',
        'payer_cuit',
        'medium',
        'status',
        'requested_on',
        'pricing_date',
        'paid_on',
        'idempotency_key',
        'created_by',
    ];

    /** @return HasMany<FeeChargeItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(FeeChargeItem::class)->orderBy('line_number');
    }

    /** @return HasMany<FeeChargeFunding, $this> */
    public function fundings(): HasMany
    {
        return $this->hasMany(FeeChargeFunding::class);
    }

    /** @return HasMany<FeeChargeReceipt, $this> */
    public function chargeReceipts(): HasMany
    {
        return $this->hasMany(FeeChargeReceipt::class)->orderByDesc('id');
    }

    /** @return HasOne<FeeChargeBalance, $this> */
    public function balance(): HasOne
    {
        return $this->hasOne(FeeChargeBalance::class, 'id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return [
            'medium' => FeeChargeMedium::class,
            'status' => FeeChargeStatus::class,
            'requested_on' => 'immutable_date',
            'pricing_date' => 'immutable_date',
            'paid_on' => 'immutable_date',
            'created_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }
}
