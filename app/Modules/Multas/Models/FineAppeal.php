<?php

declare(strict_types=1);

namespace App\Modules\Multas\Models;

use App\Models\User;
use App\Modules\Multas\Enums\FineAppealStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * El trámite del recurso del 20 %: el pago es su condición (M07) y este, lo
 * que pasa con la multa hasta el veredicto.
 *
 * @property int $id
 * @property int $fine_id
 * @property FineAppealStatus $status
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Fine $fine
 * @property-read User|null $resolver
 */
final class FineAppeal extends Model
{
    protected $table = 'fine_appeals';

    protected $fillable = [
        'fine_id',
        'status',
        'resolved_at',
        'resolved_by',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FineAppealStatus::class,
            'resolved_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
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
