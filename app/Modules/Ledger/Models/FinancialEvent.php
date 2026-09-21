<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use App\Models\User;
use App\Modules\Ledger\Enums\FinancialEventStatus;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un hecho monetario — §9.4 del DER.
 *
 * Append-only por trigger (invariante 15): el hecho —tipo, fecha, caja,
 * idempotencia— no se edita nunca. Lo que cambia es el estado, y solo
 * hacia adelante.
 *
 * Este modelo **no conoce expedientes ni cuotas**, y esa ignorancia es la
 * característica, no una omisión: es lo que va a permitir que Aranceles y
 * Multas asienten sus propios movimientos con el mismo motor.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $cash_box_id
 * @property FinancialEventType $event_type
 * @property CarbonInterface $event_date
 * @property FinancialEventStatus $status
 * @property int|null $reversal_of_id
 * @property string|null $reversal_reason
 * @property string $idempotency_key
 * @property string|null $description
 * @property int|null $created_by
 * @property int|null $posted_by
 * @property CarbonInterface|null $posted_at
 */
final class FinancialEvent extends Model
{
    use HasUlids;

    protected $fillable = [
        'public_id',
        'cash_box_id',
        'event_type',
        'event_date',
        'status',
        'reversal_of_id',
        'reversal_reason',
        'idempotency_key',
        'description',
        'created_by',
        'posted_by',
        'posted_at',
    ];

    /**
     * El ULID va en `public_id`; la clave sigue siendo el `id`.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return HasMany<JournalLine, $this> */
    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** @return BelongsTo<CashBox, $this> */
    public function cashBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class);
    }

    /** El evento que este revierte. */
    /** @return BelongsTo<self, $this> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /** @return BelongsTo<User, $this> */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', FinancialEventStatus::Posted);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event_type' => FinancialEventType::class,
            'status' => FinancialEventStatus::class,
            'event_date' => 'date',
            'posted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
