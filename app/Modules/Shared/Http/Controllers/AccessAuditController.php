<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Shared\Actions\RevokeSession;
use App\Modules\Shared\Data\AccessEventData;
use App\Modules\Shared\Data\ActiveSessionData;
use App\Modules\Shared\Enums\LoginEventType;
use App\Modules\Shared\Models\UserLoginEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accesos y sesiones de todo el sistema.
 *
 * El historial incluye los intentos contra usuarios que no existen. Esas
 * filas no apuntan a nadie y son precisamente las que hay que conservar:
 * correlacionadas por IP muestran a alguien probando nombres, que es la
 * única señal temprana que este sistema puede dar.
 */
final class AccessAuditController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $canSeeSessions = $request->user()?->can('auditoria.sesiones.ver') ?? false;

        return Inertia::render('configuracion/accesos', [
            'sessions' => $canSeeSessions ? $this->sessions($request->session()->getId()) : [],
            'events' => $this->events($filters),
            'users' => User::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
                ->values()
                ->all(),
            'eventTypes' => array_map(
                fn (LoginEventType $type): array => ['value' => $type->value, 'label' => $type->label()],
                LoginEventType::cases(),
            ),
            'filters' => $filters,
            'can' => [
                'seeSessions' => $canSeeSessions,
                'revokeSessions' => $request->user()?->can('auditoria.sesiones.revocar') ?? false,
            ],
        ]);
    }

    /**
     * Cierra una sesión ajena.
     *
     * Se identifica por el id de la sesión y no por el usuario: cerrarle
     * todo a alguien que está trabajando en la oficina porque dejó una
     * abierta en otra máquina sería un remedio peor que la enfermedad.
     */
    public function destroySession(Request $request, RevokeSession $revoke): RedirectResponse
    {
        $sessionId = (string) $request->input('sessionId');

        if ($sessionId === $request->session()->getId()) {
            return back()->withErrors([
                'sessionId' => 'Es la sesión desde la que estás trabajando: cerrala desde «Cerrar sesión».',
            ]);
        }

        $cerrada = $revoke->handle($sessionId, reason: 'cerrada por un administrador');

        return back()->with(
            'status',
            $cerrada ? 'Sesión cerrada.' : 'Esa sesión ya no estaba abierta.',
        );
    }

    /**
     * Las cien sesiones más recientes. El sistema tiene una decena de
     * operadores: si alguna vez hicieran falta más de cien filas acá, el
     * problema sería otro.
     *
     * @return list<ActiveSessionData>
     */
    private function sessions(string $currentSessionId): array
    {
        $rows = DB::table('sessions')
            ->orderByDesc('last_activity')
            ->limit(100)
            ->get(['id', 'user_id', 'ip_address', 'user_agent', 'last_activity']);

        $names = User::query()
            ->whereIn('id', $rows->pluck('user_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        return array_values($rows
            ->map(fn (object $row): ActiveSessionData => ActiveSessionData::fromRow(
                $row,
                $currentSessionId,
                $row->user_id === null ? null : $names->get($row->user_id),
            ))
            ->all());
    }

    /**
     * @param  array{usuario: int|null, tipo: string|null, ip: string|null, desde: string|null, hasta: string|null}  $filters
     * @return LengthAwarePaginator<int, AccessEventData>
     */
    private function events(array $filters): LengthAwarePaginator
    {
        return UserLoginEvent::query()
            ->with('user:id,name')
            ->when($filters['usuario'] !== null, fn ($query) => $query->where('user_id', $filters['usuario']))
            ->when($filters['tipo'] !== null, fn ($query) => $query->where('event_type', $filters['tipo']))
            ->when($filters['ip'] !== null, fn ($query) => $query->where('ip_address', $filters['ip']))
            ->when($filters['desde'] !== null, fn ($query) => $query->whereDate('created_at', '>=', $filters['desde']))
            ->when($filters['hasta'] !== null, fn ($query) => $query->whereDate('created_at', '<=', $filters['hasta']))
            ->latest('created_at')
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(AccessEventData::fromEvent(...));
    }

    /**
     * @return array{usuario: int|null, tipo: string|null, ip: string|null, desde: string|null, hasta: string|null}
     */
    private function filters(Request $request): array
    {
        $usuario = (int) $request->query('usuario', 0);
        $tipo = LoginEventType::tryFrom((string) $request->query('tipo', ''));
        $ip = trim((string) $request->query('ip', ''));
        $desde = trim((string) $request->query('desde', ''));
        $hasta = trim((string) $request->query('hasta', ''));

        return [
            'usuario' => $usuario > 0 ? $usuario : null,
            'tipo' => $tipo?->value,
            'ip' => $ip === '' ? null : $ip,
            'desde' => $desde === '' ? null : $desde,
            'hasta' => $hasta === '' ? null : $hasta,
        ];
    }
}
