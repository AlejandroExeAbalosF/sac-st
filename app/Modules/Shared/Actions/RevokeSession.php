<?php

declare(strict_types=1);

namespace App\Modules\Shared\Actions;

use App\Models\User;
use App\Modules\Shared\Enums\LoginEventType;
use App\Support\DeviceLabel;
use Illuminate\Support\Facades\DB;

/**
 * Cierra **una** sesión concreta.
 *
 * Es la que usa el administrador desde la auditoría, y por eso es distinta
 * de `RevokeUserSessions`: cerrarle todo a quien dejó una sesión abierta en
 * otra máquina lo echaría también de la que está usando para trabajar. Se
 * cierra la que sobra, no todas.
 */
final class RevokeSession
{
    public function __construct(private readonly RecordLoginEvent $recordLoginEvent) {}

    /**
     * @return bool `false` si la sesión ya no existía.
     */
    public function handle(string $sessionId, ?string $reason = null): bool
    {
        $session = DB::table('sessions')
            ->where('id', $sessionId)
            ->first(['id', 'user_id', 'ip_address', 'user_agent']);

        if ($session === null) {
            return false;
        }

        DB::table('sessions')->where('id', $sessionId)->delete();

        $user = $session->user_id === null
            ? null
            : User::query()->whereKey($session->user_id)->first();

        $this->recordLoginEvent->handle(
            type: LoginEventType::SessionRevoked,
            user: $user,
            meta: array_filter([
                'revoked_session_id' => (string) $session->id,
                'revoked_ip' => $session->ip_address,
                'revoked_device' => DeviceLabel::fromUserAgent($session->user_agent),
                'reason' => $reason,
            ], fn (mixed $value): bool => $value !== null),
        );

        return true;
    }
}
