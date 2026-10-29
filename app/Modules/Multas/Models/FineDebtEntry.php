<?php

declare(strict_types=1);

namespace App\Modules\Multas\Models;

use App\Models\User;
use App\Modules\Multas\Enums\DatePrecision;
use App\Modules\Multas\Enums\FineDebtEntryKind;
use App\Modules\Multas\Enums\FineProvenance;
use App\Modules\Multas\Enums\FineReasonCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Un movimiento de la obligación: el importe original o un ajuste.
 *
 * Append-only. Lo que estuvo mal se revierte entero y por el mismo importe;
 * una parte se corrige con otro ajuste. La base lo impone.
 *
 * @property int $id
 * @property int $fine_id
 * @property FineDebtEntryKind $kind
 * @property numeric-string $amount
 * @property Carbon|null $effective_on
 * @property DatePrecision $effective_precision
 * @property int|null $fine_document_id
 * @property FineReasonCode|null $reason_code
 * @property string|null $reason
 * @property int|null $reversal_of_id
 * @property FineProvenance $provenance
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property-read FineDocument|null $document
 * @property-read FineDebtEntry|null $reversedBy
 * @property-read User|null $creator
 */
final class FineDebtEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'fine_debt_entries';

    protected $fillable = [
        'fine_id',
        'kind',
        'amount',
        'effective_on',
        'effective_precision',
        'fine_document_id',
        'reason_code',
        'reason',
        'reversal_of_id',
        'provenance',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => FineDebtEntryKind::class,
            'reason_code' => FineReasonCode::class,
            'amount' => 'decimal:2',
            'effective_on' => 'date',
            'effective_precision' => DatePrecision::class,
            'provenance' => FineProvenance::class,
        ];
    }

    /**
     * Un aumento o una reducción sin resolución: la corrección de un importe
     * mal cargado. La base exige que lleve el motivo de su propia lista.
     */
    public function isAmountCorrection(): bool
    {
        return in_array($this->kind, [FineDebtEntryKind::Increase, FineDebtEntryKind::Reduction], true)
            && $this->fine_document_id === null;
    }

    /**
     * @return BelongsTo<Fine, $this>
     */
    public function fine(): BelongsTo
    {
        return $this->belongsTo(Fine::class);
    }

    /**
     * @return BelongsTo<FineDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(FineDocument::class, 'fine_document_id');
    }

    /**
     * @return HasOne<FineDebtEntry, $this>
     */
    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
