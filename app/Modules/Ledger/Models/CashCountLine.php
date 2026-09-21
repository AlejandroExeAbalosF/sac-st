<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use App\Modules\Ledger\Enums\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una denominación del arqueo — §9.9 del DER.
 *
 * ```text
 * CANTIDAD | BILLETES | PESOS
 *    101   |  20.000  | 2.020.000
 * ```
 *
 * Las denominaciones **no son un catálogo**: en la planilla de junio de
 * 2026 varían de una hoja a otra según qué billetes había ese día. Se
 * cargan como dato del arqueo, no como maestro.
 *
 * @property int $id
 * @property int $cash_count_id
 * @property numeric-string $denomination
 * @property int $quantity
 * @property numeric-string $subtotal
 */
final class CashCountLine extends Model
{
    protected $fillable = [
        'cash_count_id',
        'denomination',
        'quantity',
    ];

    /**
     * Los billetes que el área usa hoy, para que la pantalla los ofrezca en
     * vez de pedir que se tipeen. **No es una restricción**: el `CHECK` de
     * la base solo exige que la denominación sea positiva, porque el día
     * que el Banco Central emita otro billete la planilla lo va a tener
     * antes que este código.
     *
     * Son por moneda porque el cajon guarda las dos y contar dólares con la
     * grilla de pesos no ofrecería ni una fila utilizable. El de dos dólares
     * entra aunque casi no circule: la pantalla no deja escribir una
     * denominación que no esté en la lista, así que dejarlo afuera lo
     * volvería imposible de contar.
     *
     * @var array<string, list<int>>
     */
    public const array SUGGESTED_DENOMINATIONS = [
        Currency::Ars->value => [100_000, 50_000, 20_000, 10_000, 2_000, 1_000, 500, 200, 100, 50],
        Currency::Usd->value => [100, 50, 20, 10, 5, 2, 1],
    ];

    /** @return list<int> */
    public static function suggestedDenominations(Currency $currency): array
    {
        return self::SUGGESTED_DENOMINATIONS[$currency->value];
    }

    /** @return BelongsTo<CashCount, $this> */
    public function cashCount(): BelongsTo
    {
        return $this->belongsTo(CashCount::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'denomination' => 'decimal:2',
            'quantity' => 'integer',
            'subtotal' => 'decimal:2',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
