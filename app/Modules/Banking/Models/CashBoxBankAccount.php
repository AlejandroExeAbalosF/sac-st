<?php

declare(strict_types=1);

namespace App\Modules\Banking\Models;

use App\Models\User;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un período en que una cuenta bancaria está habilitada para una caja.
 *
 * Una cuenta que vuelve a la caja suma otro período; los de una misma cuenta
 * no se superponen. `valid_from` nulo es «inicio histórico desconocido»: lo
 * que se migró sin inventar fechas. `valid_until` incluye ese día. La base
 * impide fechas futuras, superponer períodos, más de una predeterminada por
 * caja y moneda, achicar la vigencia sobre lo ya registrado y borrar el
 * vínculo.
 *
 * @property int $id
 * @property int $cash_box_id
 * @property int $bank_account_id
 * @property string $currency
 * @property CarbonImmutable|null $valid_from
 * @property CarbonImmutable|null $valid_until
 * @property bool $is_default
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read BankAccount $bankAccount
 * @property-read CashBox $cashBox
 * @property-read User|null $creator
 */
final class CashBoxBankAccount extends Model
{
    protected $table = 'cash_box_bank_accounts';

    protected $fillable = [
        'cash_box_id',
        'bank_account_id',
        'valid_from',
        'valid_until',
        'is_default',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'immutable_date',
            'valid_until' => 'immutable_date',
            'is_default' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** Vigente ese día: el inicio, si se sabe, no es posterior; la baja, si la hay, no es anterior. */
    public function covers(CarbonInterface $date): bool
    {
        $dia = $date->toDateString();

        return ($this->valid_from === null || $this->valid_from->toDateString() <= $dia)
            && ($this->valid_until === null || $this->valid_until->toDateString() >= $dia);
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /** @return BelongsTo<CashBox, $this> */
    public function cashBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
