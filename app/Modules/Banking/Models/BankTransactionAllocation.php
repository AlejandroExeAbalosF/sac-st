<?php

declare(strict_types=1);

namespace App\Modules\Banking\Models;

use App\Models\User;
use App\Modules\Banking\Enums\BankAllocationRole;
use App\Modules\Ledger\Models\FinancialEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La costura entre el extracto y el libro — §9.3 del DER.
 *
 * Dice qué hecho contable explica un movimiento bancario, y por qué
 * importe. Un crédito puede repartirse entre varias imputaciones —dos
 * expedientes depositados juntos— mientras entre todas no reclamen más de
 * lo que el banco informó, cosa que impone un trigger.
 *
 * @property int $id
 * @property int $bank_transaction_id
 * @property int $financial_event_id
 * @property BankAllocationRole $allocation_role
 * @property numeric-string $amount
 * @property int|null $reversal_of_id
 * @property int|null $allocated_by
 * @property CarbonInterface $allocated_at
 * @property string|null $notes
 */
final class BankTransactionAllocation extends Model
{
    protected $fillable = [
        'bank_transaction_id',
        'financial_event_id',
        'allocation_role',
        'amount',
        'reversal_of_id',
        'allocated_by',
        'allocated_at',
        'notes',
    ];

    /** @return BelongsTo<BankTransaction, $this> */
    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    /** @return BelongsTo<FinancialEvent, $this> */
    public function financialEvent(): BelongsTo
    {
        return $this->belongsTo(FinancialEvent::class);
    }

    /** @return BelongsTo<self, $this> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /** @return BelongsTo<User, $this> */
    public function allocatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }

    /** Una reversión resta en vez de sumar. */
    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'allocation_role' => BankAllocationRole::class,
            'amount' => 'decimal:2',
            'allocated_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
