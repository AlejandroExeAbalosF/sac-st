<?php

declare(strict_types=1);

namespace App\Modules\Multas\Models;

use App\Modules\Shared\Models\Receipt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * El recibo de Multas de un pago, con lo que decía la multa al emitirlo.
 *
 * El papel ya está en manos del que pagó: un ajuste posterior de la multa no
 * cambia lo que dice (M09).
 *
 * @property int $id
 * @property int $fine_payment_id
 * @property int $receipt_id
 * @property bool $is_cancellation
 * @property array<string, mixed> $snapshot
 * @property Carbon|null $created_at
 * @property-read Receipt $receipt
 * @property-read FinePayment $payment
 */
final class FinePaymentReceipt extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'fine_payment_receipts';

    protected $fillable = [
        'fine_payment_id',
        'receipt_id',
        'is_cancellation',
        'snapshot',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_cancellation' => 'boolean',
            'snapshot' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Receipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    /**
     * @return BelongsTo<FinePayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(FinePayment::class, 'fine_payment_id');
    }
}
