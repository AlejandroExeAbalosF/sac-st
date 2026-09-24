<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use App\Models\User;
use App\Modules\Ledger\Enums\CashCountScope;
use App\Modules\Ledger\Enums\CashCountStatus;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Shared\Models\CashBox;
use App\Support\Money\Decimal;
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
 * @property numeric-string $difference_amount
 * @property string|null $carry_recount_reason
 * @property numeric-string|null $carry_expected_amount
 * @property numeric-string|null $carry_counted_amount
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
        'carry_recount_reason',
        'carry_expected_amount',
        'carry_counted_amount',
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

    /** Las lineas del conteo del dia: el movimiento de la jornada. */
    /** @return HasMany<CashCountLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(CashCountLine::class)
            ->where('scope', CashCountScope::Day)
            ->orderByDesc('denomination');
    }

    /**
     * Las lineas del fajo, cuando se lo abrio y se lo conto.
     *
     * Vacia en la enorme mayoria de los arqueos: recontar es excepcional.
     *
     * @return HasMany<CashCountLine, $this>
     */
    public function carryLines(): HasMany
    {
        return $this->hasMany(CashCountLine::class)
            ->where('scope', CashCountScope::Carry)
            ->orderByDesc('denomination');
    }

    /** Todas las lineas, sin importar de cual de los dos conteos son. */
    /** @return HasMany<CashCountLine, $this> */
    public function allLines(): HasMany
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

    /** Si en este arqueo se abrió el fajo de días anteriores. */
    public function recountedTheCarry(): bool
    {
        return $this->carry_counted_amount !== null;
    }

    /**
     * Lo que faltó en el fajo, o null si no se lo abrió.
     *
     * Es la parte de la diferencia del día que **no** viene de la
     * recaudación: plata vieja que el libro daba por presente y no estaba.
     * Se calcula en vez de guardarse por lo mismo que `difference_amount`
     * es una columna generada: un derivado almacenado puede quedar
     * mintiendo.
     *
     * @return numeric-string|null
     */
    public function carryDifference(): ?string
    {
        if ($this->carry_counted_amount === null || $this->carry_expected_amount === null) {
            return null;
        }

        return bcsub($this->carry_counted_amount, $this->carry_expected_amount, 2);
    }

    /** Si la recaudación contada no coincide con la calculada para el día. */
    public function hasDayDiscrepancy(): bool
    {
        $contadoDelDia = Decimal::sub(
            $this->counted_amount,
            $this->carry_counted_amount ?? '0.00',
        );

        $esperadoDelDia = Decimal::sub(
            $this->expected_amount,
            Decimal::add(
                $this->uncounted_amount,
                $this->carry_expected_amount ?? '0.00',
            ),
        );

        return ! Decimal::equals($contadoDelDia, $esperadoDelDia);
    }

    /**
     * Si la diferencia atribuida al día necesita una explicación propia.
     *
     * Al contar el cajón entero, dos diferencias internas que se compensan
     * son un cambio de reparto entre montones, no dinero faltante o sobrante.
     */
    public function requiresDifferenceExplanation(): bool
    {
        return $this->hasDayDiscrepancy()
            && (! $this->recountedTheCarry() || ! $this->isBalanced());
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
     * El último conteo físico completo anterior a una fecha.
     *
     * Es la única referencia válida para comparar composiciones: un arqueo
     * parcial conoce el importe arrastrado, pero no sus billetes. Se exige
     * además que tenga líneas porque existen aperturas históricas cargadas
     * antes de que el sistema pidiera el desglose por denominación.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeLastFullCountBefore(
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
            ->where('uncounted_amount', '0.00')
            ->whereHas('allLines')
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
            'carry_expected_amount' => 'decimal:2',
            'carry_counted_amount' => 'decimal:2',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
