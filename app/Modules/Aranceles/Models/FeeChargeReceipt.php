<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Models;

use App\Modules\Shared\Models\Receipt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un recibo de un cobro y lo que dice, congelado al emitir.
 *
 * @property int $id
 * @property int $fee_charge_id
 * @property int $receipt_id
 * @property array{payerName: string, payerDocument: string|null, lines: list<array{code: int, name: string, unitLabel: string|null, quantity: int|null, unitPrice: string|null, rate: string|null, baseAmount: string|null, amount: string}>, bankAccountNumber: string|null} $snapshot
 * @property-read Receipt $receipt
 * @property-read FeeCharge $charge
 */
class FeeChargeReceipt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['fee_charge_id', 'receipt_id', 'snapshot'];

    /** @return BelongsTo<Receipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    /** @return BelongsTo<FeeCharge, $this> */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(FeeCharge::class, 'fee_charge_id');
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }
}
