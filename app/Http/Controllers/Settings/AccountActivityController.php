<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Actions\RevokeUserSessions;
use App\Modules\Shared\Data\AccessEventData;
use App\Modules\Shared\Data\ActiveSessionData;
use App\Modules\Shared\Models\UserLoginEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La actividad de la propia cuenta: qué sesiones están abiertas y qué pasó
 * con ella.
 *
 * No lleva permiso porque no hace falta: cada uno ve lo suyo y nada más.
 * Que el titular pueda revisar sus accesos es la forma más barata de
 * detectar un uso indebido de credenciales —nadie sabe mejor que él si ese
 * ingreso del sábado a las 23:10 fue suyo—, y en un sistema que atribuye
 * movimientos de plata de terceros esa comprobación no es opcional.
 */
final class AccountActivityController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $currentSessionId = $request->session()->getId();

        $sessions = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $row): ActiveSessionData => ActiveSessionData::fromRow($row, $currentSessionId))
            ->values()
            ->all();

        $events = UserLoginEvent::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(AccessEventData::fromEvent(...));

        return Inertia::render('mi-cuenta/actividad', [
            'sessions' => $sessions,
            'events' => $events,
        ]);
    }

    /**
     * Cierra todas las sesiones menos esta.
     *
     * La actual se conserva a propósito: cerrarla también dejaría al
     * usuario en el login sin saber si la acción funcionó.
     */
    public function destroyOtherSessions(Request $request, RevokeUserSessions $revoke): RedirectResponse
    {
        $cerradas = $revoke->handle(
            $request->user(),
            exceptSessionId: $request->session()->getId(),
            reason: 'cerrada por el titular',
        );

        return back()->with('status', match ($cerradas) {
            0 => 'No había otras sesiones abiertas.',
            1 => 'Se cerró 1 sesión.',
            default => "Se cerraron {$cerradas} sesiones.",
        });
    }
}
