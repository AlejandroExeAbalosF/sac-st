<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Models;

use App\Models\User;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Haberes\Enums\DepositKind;
use App\Modules\Haberes\Enums\DepositTicketStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El comprobante del depósito que llegó con el expediente.
 *
 * @property int $id
 * @property int $expediente_id
 * @property int|null $haber_id
 * @property int|null $beneficiary_installment_id
 * @property int $bank_account_id
 * @property CarbonInterface $deposited_at
 * @property string|null $deposited_time
 * @property numeric-string $amount
 * @property string|null $operation_number
 * @property string|null $terminal
 * @property DepositKind $deposit_kind
 * @property string|null $notes
 * @property DepositTicketStatus $status
 * @property int|null $bank_transaction_id
 * @property array<string, string>|null $match_signals
 * @property int|null $matched_by
 * @property CarbonInterface|null $matched_at
 * @property string|null $discarded_reason
 */
final class DepositTicket extends Model
{
    protected $fillable = [
        'expediente_id',
        'haber_id',
        'beneficiary_installment_id',
        'bank_account_id',
        'deposited_at',
        'deposited_time',
        'amount',
        'operation_number',
        'terminal',
        'deposit_kind',
        'notes',
        'status',
        'bank_transaction_id',
        'match_signals',
        'matched_by',
        'matched_at',
        'discarded_reason',
        'created_by',
    ];

    /**
     * La cola de trabajo: los que esperan, del más viejo primero.
     *
     * El orden importa. Un ticket de hace tres semanas que sigue sin
     * aparecer es una señal —o el depósito nunca se hizo, o falta importar
     * un período— y tiene que estar arriba, no perdido al final.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWaiting(Builder $query): Builder
    {
        return $query
            ->where('status', DepositTicketStatus::Waiting->value)
            ->orderBy('deposited_at')
            ->orderBy('id');
    }

    /** @return BelongsTo<Expediente, $this> */
    public function expediente(): BelongsTo
    {
        return $this->belongsTo(Expediente::class);
    }

    /** @return BelongsTo<Haber, $this> */
    public function haber(): BelongsTo
    {
        return $this->belongsTo(Haber::class);
    }

    /** @return BelongsTo<BeneficiaryInstallment, $this> */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryInstallment::class, 'beneficiary_installment_id');
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    /** @return BelongsTo<BankTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'bank_transaction_id');
    }

    /** @return BelongsTo<User, $this> */
    public function matcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'deposit_kind' => DepositKind::class,
            'status' => DepositTicketStatus::class,
            'deposited_at' => 'date',
            'amount' => 'decimal:2',
            'match_signals' => 'array',
            'matched_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
