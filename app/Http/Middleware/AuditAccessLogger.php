<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Una línea JSON por solicitud HTTP, emitida al finalizar la respuesta. */
final class AuditAccessLogger
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) Str::uuid();
        $request->attributes->set('audit_request_id', $id);
        $request->attributes->set('audit_started_at', defined('LARAVEL_START') ? LARAVEL_START : microtime(true));

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! config('auditlog.enabled', true) || $request->is(...config('auditlog.exclude', []))) {
            return;
        }

        $status = $response->getStatusCode();
        $route = $request->route()?->getName();
        $authEvent = $request->attributes->get('audit_auth_event');
        $authRoute = $authEvent !== null || ($route !== null && preg_match('/login|logout|passkey|two-factor|password/i', $route));
        $outcome = match (true) {
            in_array($authEvent, ['login_failed', 'two_factor_failed', 'lockout'], true) => 'auth_failed',
            $status >= 500 => 'error',
            $status === 401 => 'unauthenticated',
            $status === 403 => 'denied',
            $status === 404 => 'not_found',
            $status === 419 => 'csrf',
            $status === 422 => 'validation_error',
            $status === 429 => 'rate_limited',
            $status >= 200 && $status < 400 => 'ok',
            default => 'other',
        };
        $duration = round((microtime(true) - (float) $request->attributes->get('audit_started_at', microtime(true))) * 1000, 1);
        $data = [
            'ts' => now()->timezone((string) config('app.display_timezone', config('app.timezone')))->format('Y-m-d\TH:i:s.vP'),
            'event' => $status >= 500 ? 'error' : ($authRoute ? 'auth' : 'http_request'),
            'id' => $request->attributes->get('audit_request_id'),
            'method' => $request->method(),
            // La plantilla evita guardar tokens de recuperación y otros
            // identificadores sensibles que viajan en segmentos de URL.
            'uri' => '/'.ltrim($request->route()?->uri() ?? $request->path(), '/'),
            'route' => $route,
            'status' => $status,
            'duration_ms' => $duration,
            'ip' => $request->ip(),
            'user' => $this->userData($request),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
            'outcome' => $outcome,
        ];

        $context = [];
        if ($request->attributes->has('audit_changes')) {
            $changes = $request->attributes->get('audit_changes');
            try {
                // Una transacción fallida puede haber creado eventos que luego
                // se revirtieron; sólo enlazar las filas que persisten.
                $persisted = DB::table('audit_events')
                    ->whereIn('id', array_column($changes, 'audit_id'))
                    ->pluck('id')
                    ->all();
                $changes = array_values(array_filter(
                    $changes,
                    fn (array $change): bool => in_array($change['audit_id'], $persisted, true),
                ));
            } catch (Throwable) {
                $changes = [];
            }
            if ($changes !== []) {
                $context['changes'] = $changes;
            }
        }
        if ($authEvent !== null) {
            $context['auth_event'] = $authEvent;
            if ($request->attributes->has('audit_login_event_id')) {
                $context['login_event_id'] = $request->attributes->get('audit_login_event_id');
            }
            if ($request->attributes->has('audit_auth_reason')) {
                $context['reason'] = $request->attributes->get('audit_auth_reason');
            }
        }
        if ($context !== []) {
            $data['context'] = $context;
        }

        $slowMs = (int) config('auditlog.slow_ms', 1000);
        if ($slowMs > 0 && $duration >= $slowMs) {
            $data['slow'] = true;
        }

        try {
            Log::channel('audit')->info('http_request', $data);
        } catch (Throwable $exception) {
            // La respuesta ya fue enviada. Dejar constancia por un canal separado.
            try {
                Log::channel('security')->error('No se pudo escribir el log de auditoría HTTP', [
                    'request_id' => $data['id'],
                    'exception' => $exception::class,
                ]);
            } catch (Throwable) {
                // No se puede recuperar desde la propia capa de logging.
            }
        }
    }

    /** @return array{id: int|string, username: string, name: string, role: string|null}|null */
    private function userData(Request $request): ?array
    {
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                return null;
            }

            return [
                'id' => $user->getAuthIdentifier(),
                'username' => $user->username,
                'name' => $user->name,
                'role' => $user->getRoleNames()->first(),
            ];
        } catch (Throwable) {
            // Un fallo de la base no debe impedir registrar el error HTTP.
            return null;
        }
    }
}
