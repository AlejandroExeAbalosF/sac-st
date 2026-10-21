<?php

declare(strict_types=1);

namespace App\Modules\Multas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Cuánto de lo ya cobrado de una multa pasó a ser excedente, o dejó de
 * serlo, porque cambió la obligación (M05). Positivo hacia el excedente.
 *
 * @property int $id
 * @property int $fine_id
 * @property int $event_id
 * @property numeric-string $amount
 * @property string $reason
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
final class FineExcessReclassification extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'fine_excess_reclassifications';

    protected $fillable = [
        'fine_id',
        'event_id',
        'amount',
        'reason',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }
}
