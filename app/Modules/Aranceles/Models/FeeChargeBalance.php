<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El total de un cobro y lo vinculado. Es una vista: `fee_charge_balances`.
 *
 * @property int $id
 * @property numeric-string $total
 * @property numeric-string $funded
 */
class FeeChargeBalance extends Model
{
    public $timestamps = false;

    protected $table = 'fee_charge_balances';

    protected function casts(): array
    {
        return ['total' => 'decimal:2', 'funded' => 'decimal:2'];
    }
}
