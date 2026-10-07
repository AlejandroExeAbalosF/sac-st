<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use App\Models\User;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Exceptions\CashBookNotOpenedException;
use App\Modules\Shared\Models\CashBox;
use App\Support\Money\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La apertura de un libro: una caja en una moneda.
 *
 * Pesos y dólares son dos libros sobre el mismo cajón, y cada uno se abre
 * una sola vez. Sin su apertura, el libro no admite movimientos, arqueos
 * ni cierres: lo imponen la base y `PostJournalEntry`.
 *
 * Es el hecho, no el asiento. El asiento puede no existir —una apertura
 * sin saldo, el día que se abren los dólares y no hay ninguno— o ser
 * varios —el principal y uno por cada cheque detallado—. Lo declarado
 * coincide con ellos, y la base lo controla.
 *
 * @property int $id
 * @property int $cash_box_id
 * @property Currency $currency
 * @property CarbonInterface $opened_on El primer día del libro; lo declarado es el cierre del día anterior.
 * @property numeric-string $declared_total Cero: no había nada al abrir.
 * @property int|null $opened_by
 * @property string|null $notes
 * @property CarbonInterface $created_at
 */
final class CashBookOpening extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'cash_box_id',
        'currency',
        'opened_on',
        'declared_total',
        'opened_by',
        'notes',
    ];

    /** La apertura de ese libro, si ya se hizo. */
    public static function for(int $cashBoxId, Currency $currency): ?self
    {
        return self::query()
            ->where('cash_box_id', $cashBoxId)
            ->where('currency', $currency->value)
            ->first();
    }

    /**
     * El libro admite algo con esa fecha.
     *
     * **La base ya lo impide** —`journal_lines_require_opening`,
     * `cash_counts_require_opening`, `period_closings_require_opening`—, y
     * esto no la reemplaza: lo traduce a un mensaje con salida, como
     * `assertPeriodOpen` hace con el cierre. Se llama con la caja ya
     * bloqueada, que es el mismo candado con el que se abre.
     *
     * @throws CashBookNotOpenedException
     */
    public static function assertOpen(int $cashBoxId, Currency $currency, CarbonInterface $date): void
    {
        $apertura = self::for($cashBoxId, $currency);

        if ($apertura === null) {
            throw CashBookNotOpenedException::missing($currency, $date);
        }

        if ($date->toDateString() < $apertura->opened_on->toDateString()) {
            throw CashBookNotOpenedException::before($apertura, $date);
        }
    }

    /** Se abrió declarando que no había nada. */
    public function isEmpty(): bool
    {
        return Decimal::equals($this->declared_total, '0');
    }

    /** @return BelongsTo<CashBox, $this> */
    public function cashBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class);
    }

    /** @return BelongsTo<User, $this> */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'opened_on' => 'immutable_date',
            'declared_total' => 'decimal:2',
            'created_at' => 'immutable_datetime',
        ];
    }
}
