<?php

declare(strict_types=1);

namespace App\Modules\Multas\Models;

use App\Modules\Multas\Enums\FineAppealStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * El trámite del recurso del 20 %: el pago es su condición (M07) y este, lo
 * que pasa con la multa hasta el veredicto.
 *
 * @property int $id
 * @property int $fine_id
 * @property FineAppealStatus $status
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Fine $fine
 * @property-read FineAppealVerdict|null $currentVerdict
 */
final class FineAppeal extends Model
{
    protected $table = 'fine_appeals';

    protected $fillable = [
        'fine_id',
        'status',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FineAppealStatus::class,
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
     * El veredicto vigente; los revertidos quedan en la tabla.
     *
     * @return HasOne<FineAppealVerdict, $this>
     */
    public function currentVerdict(): HasOne
    {
        return $this->hasOne(FineAppealVerdict::class)->whereNull('reverted_at');
    }

    /**
     * Su pago y, si se corrigió, los descartados que reemplazó.
     *
     * @return HasMany<FinePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(FinePayment::class);
    }
}
