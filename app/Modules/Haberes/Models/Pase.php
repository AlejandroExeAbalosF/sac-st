<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Models;

use App\Models\User;
use App\Modules\Haberes\Enums\PaseStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La nota de Pase que acompaña a una Orden — §9.7 del DER.
 *
 * **El expediente no viaja.** Al organismo superior se remiten únicamente
 * la Orden de Pago y esta nota, que se generan juntas para una cuota ya
 * financiada.
 *
 * No lleva número propio: el área confirmó que la nota real se identifica
 * por el número de su Orden. `reference_number` queda nulo, previsto por
 * el DER para el día en que eso cambie.
 *
 * @property int $id
 * @property int $payment_order_id
 * @property string|null $reference_number
 * @property string $destination
 * @property CarbonInterface $issue_date
 * @property PaseStatus $status
 * @property int|null $generated_by
 * @property int|null $signed_by
 * @property string|null $notes
 */
final class Pase extends Model
{
    /** Laravel pluralizaría «Pase» a «pases» igual, pero mejor explícito. */
    protected $table = 'pases';

    protected $fillable = [
        'payment_order_id',
        'reference_number',
        'destination',
        'issue_date',
        'status',
        'generated_by',
        'signed_by',
        'notes',
    ];

    /** @return BelongsTo<PaymentOrder, $this> */
    public function paymentOrder(): BelongsTo
    {
        return $this->belongsTo(PaymentOrder::class, 'payment_order_id');
    }

    /** @return BelongsTo<User, $this> */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /** @return BelongsTo<User, $this> */
    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PaseStatus::class,
            'issue_date' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
