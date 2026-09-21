<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Models;

use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Ledger\Models\FundReceipt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * De quién era el efectivo que se depositó — §9.5 del DER.
 *
 * Vive en Haberes y no junto al traslado porque referencia
 * `funding_allocations`, que tiene `haber_id`: es exactamente el mismo
 * motivo por el que esa tabla tampoco pudo quedar en Ledger.
 *
 * Sin esto, depositar efectivo perdería el vínculo con el beneficiario y
 * la cuota, y el dinero volvería a ser anónimo justo después de haber
 * dejado de serlo (§2.1, punto 146).
 *
 * @property int $id
 * @property int $cash_to_bank_transfer_id
 * @property int $fund_receipt_id
 * @property int|null $funding_allocation_id
 * @property numeric-string $amount
 */
final class CashToBankTransferItem extends Model
{
    protected $fillable = [
        'cash_to_bank_transfer_id',
        'fund_receipt_id',
        'funding_allocation_id',
        'amount',
    ];

    /** @return BelongsTo<CashToBankTransfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(CashToBankTransfer::class, 'cash_to_bank_transfer_id');
    }

    /** @return BelongsTo<FundReceipt, $this> */
    public function fundReceipt(): BelongsTo
    {
        return $this->belongsTo(FundReceipt::class);
    }

    /** @return BelongsTo<FundingAllocation, $this> */
    public function fundingAllocation(): BelongsTo
    {
        return $this->belongsTo(FundingAllocation::class);
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
