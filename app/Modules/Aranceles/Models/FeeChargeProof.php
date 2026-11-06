<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * El comprobante del depósito de un cobro: lo que dice el papel y su foto. Solo se
 * agrega: vale el último.
 *
 * @property int $id
 * @property int $fee_charge_id
 * @property int $attachment_id
 * @property string $amount
 * @property CarbonImmutable $deposit_date
 * @property string|null $deposit_time
 * @property string|null $operation_number
 * @property string|null $terminal
 * @property string|null $notes
 * @property string $idempotency_key
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 */
class FeeChargeProof extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'fee_charge_id',
        'attachment_id',
        'amount',
        'deposit_date',
        'deposit_time',
        'operation_number',
        'terminal',
        'notes',
        'idempotency_key',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'deposit_date' => 'immutable_date',
            'created_at' => 'immutable_datetime',
        ];
    }
}
