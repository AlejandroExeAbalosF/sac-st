<?php

declare(strict_types=1);

namespace App\Modules\Shared\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Una caja — §9.1 del DER.
 *
 * No es un cajón físico: es la clasificación contable que separa Haberes
 * de Aranceles y de Multas. Vive en Shared porque es justamente lo que
 * permite que Ledger no sepa a qué circuito pertenece un evento.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $allows_income
 * @property bool $allows_expense
 * @property bool $is_active
 */
final class CashBox extends Model
{
    /** El circuito que construye esta etapa. */
    public const string HABERES = 'haberes';

    protected $fillable = [
        'code',
        'name',
        'allows_income',
        'allows_expense',
        'is_active',
    ];

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'allows_income' => 'boolean',
            'allows_expense' => 'boolean',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
