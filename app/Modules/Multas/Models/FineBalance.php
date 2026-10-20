<?php

declare(strict_types=1);

namespace App\Modules\Multas\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El saldo de una multa, leído de la vista `fine_balances`.
 *
 * Solo lectura: no hay columna que editar, todo se calcula de la
 * obligación y de los pagos.
 *
 * @property int $fine_id
 * @property numeric-string $obligation
 * @property numeric-string $operational_paid
 * @property numeric-string $historical_paid
 * @property numeric-string $debt
 * @property numeric-string $excess
 * @property numeric-string $operational_excess
 */
final class FineBalance extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'fine_balances';

    protected $primaryKey = 'fine_id';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'obligation' => 'decimal:2',
            'operational_paid' => 'decimal:2',
            'historical_paid' => 'decimal:2',
            'debt' => 'decimal:2',
            'excess' => 'decimal:2',
            'operational_excess' => 'decimal:2',
        ];
    }
}
