<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Models;

use App\Modules\Ledger\Models\FundReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El dinero que respalda un cobro. Solo avanza: se vincula, se aplica y se libera.
 *
 * @property int $id
 * @property int $fee_charge_id
 * @property int $fund_receipt_id
 * @property numeric-string $amount
 * @property int|null $application_event_id
 * @property int|null $application_reversal_event_id
 * @property CarbonImmutable|null $released_at
 * @property int|null $released_by
 * @property int|null $created_by
 */
class FeeChargeFunding extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'fee_charge_id',
        'fund_receipt_id',
        'amount',
        'application_event_id',
        'created_by',
    ];

    /** @return BelongsTo<FundReceipt, $this> */
    public function fundReceipt(): BelongsTo
    {
        return $this->belongsTo(FundReceipt::class);
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'released_at' => 'immutable_datetime'];
    }
}
