<?php

declare(strict_types=1);

namespace App\Modules\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Cuenta bancaria del organismo.
 *
 * Es donde se establece la moneda: los movimientos la heredan sin llevar
 * columna propia.
 *
 * @property int $id
 * @property string $bank_name
 * @property string|null $account_number
 * @property string|null $cbu
 * @property string|null $alias
 * @property string $label
 * @property string $currency
 * @property bool $is_active
 */
final class BankAccount extends Model
{
    protected $fillable = [
        'bank_name',
        'account_number',
        'cbu',
        'alias',
        'label',
        'currency',
        'is_active',
    ];

    /** @return HasMany<BankStatementImport, $this> */
    public function imports(): HasMany
    {
        return $this->hasMany(BankStatementImport::class);
    }

    /** @return HasMany<BankTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    /**
     * El corte, si la cuenta lo tiene: la usan varias cajas y su saldo se
     * declaró una vez, sin repartir.
     *
     * @return HasOne<BankAccountOpening, $this>
     */
    public function opening(): HasOne
    {
        return $this->hasOne(BankAccountOpening::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
