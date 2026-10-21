<?php

declare(strict_types=1);

namespace App\Modules\Multas\Models;

use App\Models\User;
use App\Modules\Multas\Enums\AppealKind;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Enums\PresentationPrecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Un pago de una multa, presentado o recibido.
 *
 * Solo el aplicado toca la deuda y lleva recibo. Lo cobrado en la caja nace
 * aplicado; lo que llega por el banco nace informado y se aplica al
 * verificar el crédito (M02). Lo aplicado y lo descartado ya no cambian.
 *
 * @property int $id
 * @property int $fine_id
 * @property FinePaymentMedium $medium
 * @property bool $judicial_origin
 * @property string|null $judicial_reference
 * @property FinePaymentStatus $status
 * @property numeric-string $amount
 * @property Carbon|null $presented_at
 * @property PresentationPrecision $presented_precision
 * @property string|null $presentation_evidence
 * @property Carbon $reported_at
 * @property Carbon|null $declared_paid_on
 * @property int|null $payer_person_id
 * @property string $payer_name
 * @property AppealKind|null $appeal_kind
 * @property numeric-string|null $appeal_base_amount
 * @property numeric-string|null $appeal_required_amount
 * @property string|null $external_receipt_reference
 * @property numeric-string|null $external_receipt_amount
 * @property bool $appeal_repeated
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_reason
 * @property int|null $discarded_by
 * @property Carbon|null $discarded_at
 * @property string|null $discard_reason
 * @property string $idempotency_key
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property-read Fine $fine
 * @property-read FinePaymentApplication|null $application
 * @property-read FinePaymentReceipt|null $paymentReceipt
 * @property-read User|null $creator
 */
final class FinePayment extends Model
{
    protected $table = 'fine_payments';

    protected $fillable = [
        'fine_id',
        'medium',
        'judicial_origin',
        'judicial_reference',
        'status',
        'amount',
        'presented_at',
        'presented_precision',
        'presentation_evidence',
        'reported_at',
        'declared_paid_on',
        'payer_person_id',
        'payer_name',
        'appeal_kind',
        'appeal_base_amount',
        'appeal_required_amount',
        'external_receipt_reference',
        'external_receipt_amount',
        'appeal_repeated',
        'reviewed_by',
        'reviewed_at',
        'review_reason',
        'discarded_by',
        'discarded_at',
        'discard_reason',
        'idempotency_key',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'medium' => FinePaymentMedium::class,
            'judicial_origin' => 'boolean',
            'status' => FinePaymentStatus::class,
            'amount' => 'decimal:2',
            'presented_at' => 'datetime',
            'presented_precision' => PresentationPrecision::class,
            'reported_at' => 'datetime',
            'declared_paid_on' => 'date',
            'appeal_kind' => AppealKind::class,
            'appeal_base_amount' => 'decimal:2',
            'appeal_required_amount' => 'decimal:2',
            'external_receipt_amount' => 'decimal:2',
            'appeal_repeated' => 'boolean',
            'reviewed_at' => 'datetime',
            'discarded_at' => 'datetime',
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
     * @return HasOne<FinePaymentApplication, $this>
     */
    public function application(): HasOne
    {
        return $this->hasOne(FinePaymentApplication::class);
    }

    /**
     * @return HasOne<FinePaymentReceipt, $this>
     */
    public function paymentReceipt(): HasOne
    {
        return $this->hasOne(FinePaymentReceipt::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
