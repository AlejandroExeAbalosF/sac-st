<?php

declare(strict_types=1);

namespace App\Modules\Shared\Actions;

use App\Models\User;
use App\Modules\Shared\Enums\LoginEventType;
use App\Modules\Shared\Enums\LoginFailureReason;
use App\Modules\Shared\Models\UserLoginEvent;
use App\Support\DeviceLabel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Registra un evento del historial de accesos.
 *
 * Nunca interrumpe el flujo de autenticación: si por lo que sea no se
 * puede escribir la fila, se deja constancia en el canal `security` y el
 * usuario entra igual. Un problema de auditoría no puede dejar al área
 * sin poder trabajar; sí tiene que quedar visible para quien opera el
 * sistema.
 */
final class RecordLoginEvent
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function handle(
        LoginEventType $type,
        ?User $user = null,
        ?string $usernameAttempted = null,
        ?LoginFailureReason $reason = null,
        array $meta = [],
        ?Request $request = null,
    ): ?UserLoginEvent {
        $request ??= request();

        try {
            return UserLoginEvent::query()->create([
                'user_id' => $user?->id,
                'username_attempted' => $usernameAttempted,
                'event_type' => $type,
                'failure_reason' => $reason,
                'session_id' => $request->hasSession() ? $request->session()->getId() : null,
                'ip_address' => $request->ip(),
                'user_agent' => $this->truncateUserAgent($request->userAgent()),
                'device_label' => DeviceLabel::fromUserAgent($request->userAgent()),
                'meta' => $meta === [] ? null : $meta,
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::channel('security')->error('No se pudo registrar el evento de acceso', [
                'event_type' => $type->value,
                'user_id' => $user?->id,
                'username_attempted' => $usernameAttempted,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /** Un user agent legítimo no pasa de ~400 caracteres; el resto es ruido. */
    private function truncateUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }

        return mb_substr($userAgent, 0, 512);
    }
}
