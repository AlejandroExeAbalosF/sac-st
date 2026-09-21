<?php

declare(strict_types=1);

namespace App\Modules\Banking\Models;

use App\Models\User;
use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El efectivo que salió de la caja camino al banco — §9.5 del DER.
 *
 * Vive en Banking y no en Haberes porque no sabe nada de expedientes: lo
 * que traslada es dinero, y quién era su dueño lo dicen los ítems. Esa
 * separación es lo que permite que Aranceles y Multas lo reutilicen.
 *
 * @property int $id
 * @property int $deposit_event_id
 * @property int|null $credit_event_id
 * @property int $cash_box_id
 * @property int $bank_account_id
 * @property numeric-string $amount
 * @property CarbonInterface $deposit_date
 * @property string|null $deposit_time
 * @property string|null $deposit_operation_number
 * @property string|null $deposit_terminal
 * @property int|null $deposited_by
 * @property string|null $notes
 * @property CashTransferStatus $status
 */
final class CashToBankTransfer extends Model
{
    protected $fillable = [
        'deposit_event_id',
        'credit_event_id',
        'cash_box_id',
        'bank_account_id',
        'amount',
        'deposit_date',
        'deposit_time',
        'deposit_operation_number',
        'deposit_terminal',
        'deposited_by',
        'notes',
        'status',
    ];

    /** @return BelongsTo<FinancialEvent, $this> */
    public function depositEvent(): BelongsTo
    {
        return $this->belongsTo(FinancialEvent::class, 'deposit_event_id');
    }

    /** @return BelongsTo<FinancialEvent, $this> */
    public function creditEvent(): BelongsTo
    {
        return $this->belongsTo(FinancialEvent::class, 'credit_event_id');
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
    public function depositedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deposited_by');
    }

    /**
     * Los que todavía esperan el crédito del extracto.
     *
     * Es la cola de trabajo real: un traslado que envejece acá es efectivo
     * que salió de la caja y que el banco nunca acreditó.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAwaitingCredit(Builder $query): void
    {
        $query->where('status', CashTransferStatus::Deposited);
    }

    /** @param  Builder<self>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', '!=', CashTransferStatus::Cancelled);
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'deposit_date' => 'date',
            'status' => CashTransferStatus::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
