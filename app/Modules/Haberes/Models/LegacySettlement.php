<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Models;

use App\Models\User;
use App\Modules\Ledger\Enums\PaymentMedium;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Que una cuota se pagó antes de la apertura, y cómo.
 *
 * Append-only con anulación: un registro equivocado se anula con motivo y
 * la cuota vuelve a quedar pendiente.
 *
 * @property int $id
 * @property int $haber_id
 * @property int $beneficiary_installment_id
 * @property numeric-string $amount
 * @property CarbonInterface $paid_on
 * @property PaymentMedium $payment_medium
 * @property string|null $notes
 * @property int|null $recorded_by
 * @property CarbonInterface $recorded_at
 * @property CarbonInterface|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 */
final class LegacySettlement extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'haber_id',
        'beneficiary_installment_id',
        'amount',
        'paid_on',
        'payment_medium',
        'notes',
        'recorded_by',
        'recorded_at',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    /** @return BelongsTo<BeneficiaryInstallment, $this> */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryInstallment::class, 'beneficiary_installment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return HasMany<LegacyDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(LegacyDocument::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_on' => 'date',
            'payment_medium' => PaymentMedium::class,
            'recorded_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
