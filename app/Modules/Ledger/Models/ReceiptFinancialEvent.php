<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use App\Modules\Shared\Models\Receipt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Qué hecho monetario documenta un comprobante — §9.8 del DER.
 *
 * Un recibo de ingreso puede respaldar varias asignaciones, porque una
 * cuota puede financiarse con más de un ingreso y el comprobante se emite
 * una sola vez, al completarse.
 *
 * @property int $id
 * @property int $receipt_id
 * @property int $financial_event_id
 */
final class ReceiptFinancialEvent extends Model
{
    protected $fillable = [
        'receipt_id',
        'financial_event_id',
    ];

    /** @return BelongsTo<Receipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    /** @return BelongsTo<FinancialEvent, $this> */
    public function financialEvent(): BelongsTo
    {
        return $this->belongsTo(FinancialEvent::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
