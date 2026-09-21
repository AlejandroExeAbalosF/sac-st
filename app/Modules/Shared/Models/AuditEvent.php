<?php

declare(strict_types=1);

namespace App\Modules\Shared\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un cambio del dominio y quién lo hizo.
 *
 * Append-only: la base rechaza `UPDATE` y `DELETE` con un trigger. El
 * modelo no expone forma de editarlos, pero la garantía está abajo.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $action
 * @property string $subject_type
 * @property int $subject_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property array<string, mixed>|null $metadata
 * @property Carbon $occurred_at
 */
final class AuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'old_values',
        'new_values',
        'metadata',
        'ip_address',
        'user_agent',
        'occurred_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * El valor que tenía un campo antes del cambio.
     *
     * Lo usan las reactivaciones: el estado previo vive acá y no en una
     * columna aparte, porque es exactamente lo que la auditoría registra.
     */
    public function before(string $campo): mixed
    {
        return $this->old_values[$campo] ?? null;
    }

    /**
     * @param  Builder<AuditEvent>  $query
     * @return Builder<AuditEvent>
     */
    public function scopeForSubject(Builder $query, string $type, int $id): Builder
    {
        return $query
            ->where('subject_type', $type)
            ->where('subject_id', $id)
            ->orderByDesc('occurred_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
