<?php

declare(strict_types=1);

namespace App\Modules\Banking\Models;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El corte de una cuenta bancaria compartida.
 *
 * El saldo que el banco informa al cierre de `cutoff_on`, sin repartir
 * entre las cajas que usan la cuenta. Lo que el extracto trae hasta ese
 * día ya está adentro: no entra a ningún libro como dinero nuevo. Se
 * declara una vez y no se corrige.
 *
 * @property int $id
 * @property int $bank_account_id
 * @property CarbonInterface $cutoff_on El último día incluido en el saldo.
 * @property numeric-string $statement_balance
 * @property int $declared_by
 * @property string|null $notes
 * @property CarbonInterface $created_at
 */
final class BankAccountOpening extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'bank_account_id',
        'cutoff_on',
        'statement_balance',
        'declared_by',
        'notes',
    ];

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /** @return BelongsTo<User, $this> */
    public function declarer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declared_by');
    }

    protected function casts(): array
    {
        return [
            'cutoff_on' => 'immutable_date',
            'statement_balance' => 'decimal:2',
            'created_at' => 'immutable_datetime',
        ];
    }
}
