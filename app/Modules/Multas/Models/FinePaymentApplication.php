<?php

declare(strict_types=1);

namespace App\Modules\Multas\Models;

use App\Models\User;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Multas\Enums\FineTimeliness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * El pago tocó dinero real: una recepción entera, aplicada a la multa.
 *
 * Lo que cubre la deuda es recaudación; lo que la excede, excedente (M05).
 * El término se juzga acá una sola vez y no cambia.
 *
 * @property int $id
 * @property int $fine_payment_id
 * @property int $fine_id
 * @property int $fund_receipt_id
 * @property int $application_event_id
 * @property numeric-string $amount
 * @property numeric-string $applied_amount
 * @property numeric-string $excess_amount
 * @property FineTimeliness $timeliness
 * @property Carbon|null $term_ends_on
 * @property int|null $applied_by
 * @property Carbon $applied_at
 * @property-read FundReceipt $fundReceipt
 * @property-read User|null $applier
 */
final class FinePaymentApplication extends Model
{
    public $timestamps = false;

    protected $table = 'fine_payment_applications';

    protected $fillable = [
        'fine_payment_id',
        'fine_id',
        'fund_receipt_id',
        'application_event_id',
        'amount',
        'applied_amount',
        'excess_amount',
        'timeliness',
        'term_ends_on',
        'applied_by',
        'applied_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'applied_amount' => 'decimal:2',
            'excess_amount' => 'decimal:2',
            'timeliness' => FineTimeliness::class,
            'term_ends_on' => 'date',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<FundReceipt, $this>
     */
    public function fundReceipt(): BelongsTo
    {
        return $this->belongsTo(FundReceipt::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
