<?php

declare(strict_types=1);

namespace App\Modules\Multas\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Une un pago bancario anulado con el que lo reemplazó: lo anterior queda a la
 * vista. El motivo está en la anulación. La base no deja editarla.
 *
 * @property int $id
 * @property int $corrected_payment_id
 * @property int $new_payment_id
 * @property int $from_fine_id
 * @property int $to_fine_id
 * @property int|null $corrected_by
 * @property Carbon|null $corrected_at
 */
final class FinePaymentCorrection extends Model
{
    public $timestamps = false;

    protected $table = 'fine_payment_corrections';

    protected $fillable = [
        'corrected_payment_id',
        'new_payment_id',
        'from_fine_id',
        'to_fine_id',
        'corrected_by',
        'corrected_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['corrected_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<FinePayment, $this>
     */
    public function correctedPayment(): BelongsTo
    {
        return $this->belongsTo(FinePayment::class, 'corrected_payment_id');
    }

    /**
     * @return BelongsTo<FinePayment, $this>
     */
    public function newPayment(): BelongsTo
    {
        return $this->belongsTo(FinePayment::class, 'new_payment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
