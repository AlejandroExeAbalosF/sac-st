<?php

declare(strict_types=1);

namespace App\Modules\Banking\Models;

use App\Modules\Ledger\Models\FundReceipt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Qué viajó en un traslado: una recepción de la caja, entera o en parte.
 *
 * Vive junto al traslado porque la tabla es genérica. Quién era el dueño de
 * esa plata lo dice el módulo de la caja: Haberes extiende este modelo con
 * la asignación a la cuota (`funding_allocation_id`); una caja que deposita
 * sus cobros enteros —Multas— no la usa, y llega al pago por la recepción.
 *
 * @property int $id
 * @property int $cash_to_bank_transfer_id
 * @property int $fund_receipt_id
 * @property int|null $funding_allocation_id
 * @property numeric-string $amount
 */
class CashToBankTransferItem extends Model
{
    protected $table = 'cash_to_bank_transfer_items';

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

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
