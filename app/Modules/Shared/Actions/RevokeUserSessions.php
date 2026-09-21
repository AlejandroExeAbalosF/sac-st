<?php

declare(strict_types=1);

namespace App\Modules\Shared\Actions;

use App\Models\User;
use App\Modules\Shared\Enums\LoginEventType;
use App\Support\DeviceLabel;
use Illuminate\Support\Facades\DB;

/**
 * Cierra sesiones abiertas de un usuario.
 *
 * Con el driver `database` alcanza con borrar la fila: el request
 * siguiente no encuentra la sesión y el usuario vuelve al login. No hace
 * falta rotar el `remember_token` ni pedirle la contraseña a nadie, que es
 * lo que exige `logoutOtherDevices`.
 *
 * Cada sesión cerrada deja su propio evento en el historial. Uno solo por
 * lote diría «se cerraron sesiones» sin decir cuáles, y el historial existe
 * justamente para poder reconstruir qué pasó con cada acceso.
 */
final class RevokeUserSessions
{
    public function __construct(private readonly RecordLoginEvent $recordLoginEvent) {}

    /**
     * @param  string|null  $exceptSessionId  Sesión a conservar: la de quien ejecuta la acción.
     * @return int Cuántas sesiones se cerraron.
     */
    public function handle(User $user, ?string $exceptSessionId = null, ?string $reason = null): int
    {
        $sessions = DB::table('sessions')
            ->where('user_id', $user->id)
            ->when($exceptSessionId !== null, fn ($query) => $query->where('id', '!=', $exceptSessionId))
            ->get(['id', 'ip_address', 'user_agent']);

        if ($sessions->isEmpty()) {
            return 0;
        }

        DB::table('sessions')
            ->whereIn('id', $sessions->pluck('id')->all())
            ->delete();

        foreach ($sessions as $session) {
            $this->recordLoginEvent->handle(
                type: LoginEventType::SessionRevoked,
                user: $user,
                meta: array_filter([
                    'revoked_session_id' => $session->id,
                    'revoked_ip' => $session->ip_address,
                    'revoked_device' => DeviceLabel::fromUserAgent($session->user_agent),
                    'reason' => $reason,
                ], fn (mixed $value): bool => $value !== null),
            );
        }

        return $sessions->count();
    }
}
