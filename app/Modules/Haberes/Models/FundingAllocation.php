<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Models;

use App\Models\User;
use App\Modules\Haberes\Enums\AllocationKind;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Ledger\Models\FundReceipt;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El dinero que financia una cuota — §9.4 del DER.
 *
 * Une una recepción con la cuota que financia. Es el acto que convierte
 * plata anónima en plata de alguien, y el que después habilita la Orden de
 * Pago.
 *
 * @property int $id
 * @property int $allocation_event_id
 * @property int $fund_receipt_id
 * @property int $haber_id
 * @property int $beneficiary_installment_id
 * @property AllocationKind $allocation_kind
 * @property int|null $reversal_of_id
 * @property numeric-string $amount
 * @property int|null $allocated_by
 * @property CarbonInterface $allocated_at
 * @property int|null $match_score
 * @property array<string, mixed>|null $match_explanation
 * @property string|null $notes
 */
final class FundingAllocation extends Model
{
    protected $fillable = [
        'allocation_event_id',
        'fund_receipt_id',
        'haber_id',
        'beneficiary_installment_id',
        'allocation_kind',
        'reversal_of_id',
        'amount',
        'allocated_by',
        'allocated_at',
        'match_score',
        'match_explanation',
        'notes',
    ];

    /** @return BelongsTo<FinancialEvent, $this> */
    public function allocationEvent(): BelongsTo
    {
        return $this->belongsTo(FinancialEvent::class, 'allocation_event_id');
    }

    /** @return BelongsTo<FundReceipt, $this> */
    public function fundReceipt(): BelongsTo
    {
        return $this->belongsTo(FundReceipt::class, 'fund_receipt_id');
    }

    /** @return BelongsTo<BeneficiaryInstallment, $this> */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryInstallment::class, 'beneficiary_installment_id');
    }

    /** @return BelongsTo<Haber, $this> */
    public function haber(): BelongsTo
    {
        return $this->belongsTo(Haber::class, 'haber_id');
    }

    /** @return BelongsTo<User, $this> */
    public function allocatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }

    /**
     * Las que suman. Una reversión resta y se cuenta aparte.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('allocation_kind', '!=', AllocationKind::Reversal);
    }

    /**
     * Asignaciones originales a las que todavía les queda importe en pie.
     *
     * `live()` distingue originales de reversiones para poder sumar ambas;
     * esta variante sirve cuando el consumidor necesita filas utilizables,
     * no el historial de lo que alguna vez estuvo asignado.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWithRemainingBalance(Builder $query): Builder
    {
        return $query
            ->live()
            ->whereRaw(
                'funding_allocations.amount > COALESCE(('
                .'SELECT SUM(reversions.amount) FROM funding_allocations AS reversions '
                .'WHERE reversions.reversal_of_id = funding_allocations.id'
                .'), 0)',
            );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'allocation_kind' => AllocationKind::class,
            'amount' => 'decimal:2',
            'allocated_at' => 'datetime',
            'match_explanation' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
