<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use App\Models\User;
use App\Modules\Ledger\Enums\CashCountStatus;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un arqueo de caja — §9.9 del DER.
 *
 * El reverso de la planilla: se cuentan los billetes por denominación y el
 * total se compara con lo que dice el libro. Los cheques **no se cuentan
 * acá** —se listan desde `fund_receipts` con `cheque_status = 'in_custody'`—,
 * que es como funciona el papel desde siempre.
 *
 * @property int $id
 * @property int $cash_box_id
 * @property CarbonImmutable $counted_on
 * @property int $sequence
 * @property CarbonImmutable $counted_at
 * @property Currency $currency
 * @property numeric-string $expected_amount
 * @property numeric-string $counted_amount
 * @property numeric-string $uncounted_amount
 * @property string|null $uncounted_reason
 * @property numeric-string $difference_amount
 * @property CashCountStatus $status
 * @property string|null $explanation
 * @property int|null $performed_by
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property int|null $adjustment_event_id
 */
final class CashCount extends Model
{
    /**
     * `difference_amount` queda afuera a propósito: es una columna
     * generada por PostgreSQL. Escribirla sería un error contra la base, y
     * no declararla acá lo vuelve imposible desde PHP.
     *
     * @var list<string>
     */
    protected $fillable = [
        'cash_box_id',
        'counted_on',
        'sequence',
        'counted_at',
        'currency',
        'expected_amount',
        'counted_amount',
        'uncounted_amount',
        'uncounted_reason',
        'status',
        'explanation',
        'performed_by',
        'reviewed_by',
        'reviewed_at',
        'adjustment_event_id',
    ];

    /** @return BelongsTo<CashBox, $this> */
    public function cashBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class);
    }

    /** @return HasMany<CashCountLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(CashCountLine::class)->orderByDesc('denomination');
    }

    /** @return BelongsTo<User, $this> */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * El asiento que imputó la diferencia, si se la regularizó.
     *
     * @return BelongsTo<FinancialEvent, $this>
     */
    public function adjustmentEvent(): BelongsTo
    {
        return $this->belongsTo(FinancialEvent::class, 'adjustment_event_id');
    }

    /**
     * Lo revisó quien lo contó: no hubo segunda firma.
     *
     * Se admite —el área trabaja con un equipo chico— pero se muestra
     * siempre, porque un arqueo aprobado por su propio autor prueba menos
     * que uno que pasó por otro par de ojos. Se deduce de las dos columnas
     * en vez de guardarse aparte: un dato derivado que se almacena es un
     * dato que puede quedar mintiendo.
     */
    public function wasSelfReviewed(): bool
    {
        return $this->reviewed_by !== null
            && $this->reviewed_by === $this->performed_by;
    }

    /**
     * Si el conteo cuadra con el libro.
     *
     * Cuadrar **no significa que se haya contado todo**: un arqueo con
     * `uncounted_amount` distinto de cero puede dar diferencia cero porque
     * el fajo no recontado se declaró. Para saber si el conteo fue completo
     * está `wasFullyCounted()`.
     */
    public function isBalanced(): bool
    {
        return bccomp($this->difference_amount, '0', 2) === 0;
    }

    /** Si se contó el cajón entero o quedó un fajo declarado sin recontar. */
    public function wasFullyCounted(): bool
    {
        return bccomp($this->uncounted_amount, '0', 2) === 0;
    }

    /**
     * El último arqueo firme anterior a una fecha.
     *
     * Sirve de referencia, no de plantilla: lo que se copia de él es el
     * fajo que no se recuenta --una declaración, siempre la misma-- y
     * nunca las denominaciones. Precargar un conteo lo volvería una
     * confirmación de lo de ayer, y un arqueo copiado es indistinguible
     * de uno real.
     *
     * Solo los firmes: un borrador no concluyó nada.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeLastFirmBefore(
        Builder $query,
        int $cashBoxId,
        Currency $currency,
        CarbonImmutable $date,
    ): Builder {
        return $query
            ->where('cash_box_id', $cashBoxId)
            ->where('currency', $currency)
            ->whereDate('counted_on', '<', $date)
            ->where('status', '!=', CashCountStatus::Draft)
            ->orderByDesc('counted_on')
            ->orderByDesc('sequence');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForBox(Builder $query, int $cashBoxId): Builder
    {
        return $query->where('cash_box_id', $cashBoxId);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'counted_on' => 'immutable_date',
            'counted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'currency' => Currency::class,
            'status' => CashCountStatus::class,
            'sequence' => 'integer',
            'expected_amount' => 'decimal:2',
            'counted_amount' => 'decimal:2',
            'uncounted_amount' => 'decimal:2',
            'difference_amount' => 'decimal:2',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
