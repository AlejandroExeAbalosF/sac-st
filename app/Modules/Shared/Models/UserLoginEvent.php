<?php

declare(strict_types=1);

namespace App\Modules\Shared\Models;

use App\Models\User;
use App\Modules\Shared\Enums\LoginEventType;
use App\Modules\Shared\Enums\LoginFailureReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Historial de accesos. Append-only, impuesto por trigger en la base.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $username_attempted
 * @property LoginEventType $event_type
 * @property LoginFailureReason|null $failure_reason
 * @property string|null $session_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $device_label
 * @property array<string, mixed>|null $meta
 * @property Carbon $created_at
 */
class UserLoginEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'username_attempted',
        'event_type',
        'failure_reason',
        'session_id',
        'ip_address',
        'user_agent',
        'device_label',
        'meta',
        'created_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * La tabla es append-only en la base; esto evita además que un
     * `save()` distraído llegue siquiera a golpear el trigger.
     */
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('user_login_events es append-only: no se modifica.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('user_login_events es append-only: no se elimina.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => LoginEventType::class,
            'failure_reason' => LoginFailureReason::class,
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
