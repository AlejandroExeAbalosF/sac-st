<?php

declare(strict_types=1);

namespace App\Modules\Multas\Models;

use App\Models\User;
use App\Modules\Multas\Enums\FineReasonCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * La anulación de un pago: un cobro de la caja cuyo dinero nunca entró, un
 * cheque rechazado o un pago bancario. Queda con su motivo y los dos asientos:
 * la reversión de la aplicación y el que saca el dinero de los libros, que en
 * un cheque rechazado no es una reversión. La base no deja editarla.
 *
 * @property int $id
 * @property int $fine_payment_id
 * @property int $fine_id
 * @property FineReasonCode $reason_code
 * @property string|null $reason
 * @property int $application_reversal_event_id
 * @property int $funds_exit_event_id
 * @property int|null $voided_by
 * @property Carbon|null $voided_at
 * @property-read User|null $voider
 */
final class FinePaymentVoid extends Model
{
    public $timestamps = false;

    protected $table = 'fine_payment_voids';

    protected $fillable = [
        'fine_payment_id',
        'fine_id',
        'reason_code',
        'reason',
        'application_reversal_event_id',
        'funds_exit_event_id',
        'voided_by',
        'voided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason_code' => FineReasonCode::class,
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
