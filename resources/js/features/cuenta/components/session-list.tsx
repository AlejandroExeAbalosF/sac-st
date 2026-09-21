import { Laptop, MapPin } from 'lucide-react';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { dateTime } from '@/lib/format';

type Sesion = App.Modules.Shared.Data.ActiveSessionData;

/**
 * Las sesiones abiertas.
 *
 * La misma lista sirve para la cuenta propia y para la auditoría del
 * sistema: cambia si se muestra la columna del usuario y si hay un botón
 * para cerrarla. La sesión actual se marca y nunca ofrece ese botón —
 * cerrarla desde acá dejaría al operador en el login sin entender por qué.
 */
export default function SessionList({
    sessions,
    showUser = false,
    onRevoke,
    emptyLabel = 'No hay sesiones abiertas.',
}: {
    sessions: Sesion[];
    showUser?: boolean;
    onRevoke?: (session: Sesion) => void;
    emptyLabel?: string;
}) {
    if (sessions.length === 0) {
        return (
            <p className="px-5 py-10 text-center text-sm text-muted-foreground">
                {emptyLabel}
            </p>
        );
    }

    return (
        <ul className="divide-y">
            {sessions.map((session) => (
                <li
                    key={session.id}
                    className="flex flex-wrap items-center gap-3 px-5 py-3.5"
                >
                    <Laptop
                        className="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />

                    <div className="min-w-0 flex-1">
                        <p className="flex flex-wrap items-center gap-2 text-sm font-medium">
                            {showUser && (
                                <span className="truncate">
                                    {session.userName ?? 'Sin usuario'}
                                </span>
                            )}
                            <span
                                className={
                                    showUser
                                        ? 'truncate text-muted-foreground'
                                        : 'truncate'
                                }
                            >
                                {session.deviceLabel ??
                                    'Dispositivo desconocido'}
                            </span>
                            {session.isCurrent && (
                                <StatusBadge
                                    label="Esta sesión"
                                    tone="progress"
                                />
                            )}
                        </p>
                        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <MapPin className="size-3" aria-hidden="true" />
                            <span className="font-mono tabular-nums">
                                {session.ipAddress ?? 'IP desconocida'}
                            </span>
                            <span aria-hidden="true">·</span>
                            <span>
                                Última actividad{' '}
                                <time dateTime={session.lastActivityAt}>
                                    {dateTime(session.lastActivityAt)}
                                </time>
                            </span>
                        </p>
                    </div>

                    {onRevoke && !session.isCurrent && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => onRevoke(session)}
                        >
                            Cerrar
                        </Button>
                    )}
                </li>
            ))}
        </ul>
    );
}
