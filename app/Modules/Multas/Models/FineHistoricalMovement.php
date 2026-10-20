<?php

declare(strict_types=1);

namespace App\Modules\Multas\Models;

use App\Models\User;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Multas\Enums\DatePrecision;
use App\Modules\Multas\Enums\FineProvenance;
use App\Modules\Multas\Enums\HistoricalMovementKind;
use App\Modules\Multas\Enums\HistoricalMovementStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un antecedente: lo que se pagó o se devolvió sin pasar por el libro.
 *
 * Nunca genera asientos. Cuenta en el saldo de la multa recién cuando
 * alguien lo valida, y se revisa una sola vez.
 *
 * @property int $id
 * @property int $fine_id
 * @property HistoricalMovementKind $kind
 * @property numeric-string $amount
 * @property Carbon|null $occurred_on
 * @property DatePrecision $occurred_precision
 * @property string $evidence_text
 * @property int|null $bank_transaction_id El crédito del banco que lo respalda, anterior a la apertura de la caja.
 * @property HistoricalMovementStatus $status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property FineProvenance $provenance
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property-read User|null $creator
 * @property-read User|null $reviewer
 * @property-read BankTransaction|null $bankTransaction
 */
final class FineHistoricalMovement extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'fine_historical_movements';

    protected $fillable = [
        'fine_id',
        'kind',
        'amount',
        'occurred_on',
        'occurred_precision',
        'evidence_text',
        'bank_transaction_id',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'provenance',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => HistoricalMovementKind::class,
            'amount' => 'decimal:2',
            'occurred_on' => 'date',
            'occurred_precision' => DatePrecision::class,
            'status' => HistoricalMovementStatus::class,
            'reviewed_at' => 'datetime',
            'provenance' => FineProvenance::class,
        ];
    }

    /**
     * @return BelongsTo<Fine, $this>
     */
    public function fine(): BelongsTo
    {
        return $this->belongsTo(Fine::class);
    }

    /**
     * @return BelongsTo<BankTransaction, $this>
     */
    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
