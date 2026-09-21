import { LogIn, LogOut, ShieldAlert, ShieldCheck } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { dateTime } from '@/lib/format';

type RecentAccess = App.Modules.Shared.Data.RecentAccessData;
type LoginEventType = App.Modules.Shared.Enums.LoginEventType;

const ICONO: Record<LoginEventType, LucideIcon> = {
    login_success: LogIn,
    login_failed: ShieldAlert,
    logout: LogOut,
    password_reset: ShieldCheck,
    password_changed: ShieldCheck,
    two_factor_challenged: ShieldCheck,
    two_factor_failed: ShieldAlert,
    session_revoked: ShieldAlert,
    lockout: ShieldAlert,
};

const SOSPECHOSO: ReadonlySet<LoginEventType> = new Set([
    'login_failed',
    'two_factor_failed',
    'lockout',
]);

/**
 * Actividad reciente de la propia cuenta.
 *
 * Que cada uno vea sus últimos accesos es la forma más barata de detectar
 * un uso indebido de credenciales: nadie sabe mejor que el titular si ese
 * ingreso del sábado a las 23:10 fue suyo.
 */
export default function RecentAccessList({
    events,
}: {
    events: RecentAccess[];
}) {
    if (events.length === 0) {
        return (
            <p className="px-5 py-10 text-center text-sm text-muted-foreground">
                Todavía no hay actividad registrada en esta cuenta.
            </p>
        );
    }

    return (
        <ul className="divide-y">
            {events.map((event) => {
                const Icono = ICONO[event.type];
                const alerta = SOSPECHOSO.has(event.type);

                return (
                    <li
                        key={event.id}
                        className="flex items-center gap-3 px-5 py-3.5"
                    >
                        <Icono
                            className={
                                alerta
                                    ? 'size-4 shrink-0 text-destructive-strong'
                                    : 'size-4 shrink-0 text-muted-foreground'
                            }
                            aria-hidden="true"
                        />

                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-medium">
                                {event.label}
                            </p>
                            <p className="truncate text-xs text-muted-foreground">
                                {event.deviceLabel ?? 'Dispositivo desconocido'}
                                {event.ipAddress && ` · ${event.ipAddress}`}
                            </p>
                        </div>

                        <time
                            className="shrink-0 font-mono text-xs text-muted-foreground tabular-nums"
                            dateTime={event.occurredAt}
                        >
                            {dateTime(event.occurredAt)}
                        </time>
                    </li>
                );
            })}
        </ul>
    );
}
