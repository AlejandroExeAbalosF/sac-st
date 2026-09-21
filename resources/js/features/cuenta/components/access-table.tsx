import { dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';

type Evento = App.Modules.Shared.Data.AccessEventData;

/**
 * El historial de accesos, en tabla.
 *
 * La columna del usuario solo aparece en la auditoría del sistema: en la
 * cuenta propia todas las filas son del mismo y repetir el nombre
 * cincuenta veces no agrega nada.
 *
 * Un intento fallido se resalta con un filete al costado y no con la fila
 * pintada entera: la tabla se sigue leyendo como una lista y aun así el ojo
 * encuentra los intentos raros sin filtrar.
 */
export default function AccessTable({
    events,
    showUser = false,
    emptyLabel,
}: {
    events: Evento[];
    showUser?: boolean;
    emptyLabel: string;
}) {
    if (events.length === 0) {
        return (
            <p className="px-5 py-12 text-center text-sm text-muted-foreground">
                {emptyLabel}
            </p>
        );
    }

    return (
        <div className="overflow-x-auto">
            <table className="w-full border-collapse text-left">
                <caption className="sr-only">Historial de accesos</caption>
                <thead className="sticky top-0 z-10">
                    <tr className="border-b bg-muted text-xs tracking-wide text-field-label uppercase shadow-[0_1px_0_0_var(--border)]">
                        <th scope="col" className="px-4 py-2.5 font-medium">
                            Evento
                        </th>
                        {showUser && (
                            <th scope="col" className="py-2.5 pr-5 font-medium">
                                Usuario
                            </th>
                        )}
                        <th scope="col" className="py-2.5 pr-5 font-medium">
                            Dispositivo
                        </th>
                        <th scope="col" className="py-2.5 pr-5 font-medium">
                            IP
                        </th>
                        <th
                            scope="col"
                            className="py-2.5 pr-4 text-right font-medium"
                        >
                            Cuándo
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {events.map((event) => (
                        <tr
                            key={event.id}
                            className={cn(
                                'border-b transition-colors hover:bg-accent/30',
                                event.suspicious &&
                                    'bg-destructive-soft/40 shadow-[inset_3px_0_0_0_var(--destructive)]',
                            )}
                        >
                            <td className="px-4 py-3">
                                <p
                                    className={cn(
                                        'text-sm',
                                        event.suspicious &&
                                            'font-medium text-destructive-strong',
                                    )}
                                >
                                    {event.label}
                                </p>
                                {event.failureReason && (
                                    <p className="text-xs text-destructive-strong">
                                        {event.failureReason}
                                    </p>
                                )}
                            </td>

                            {showUser && (
                                <td className="py-3 pr-5 text-sm">
                                    {event.userName ?? (
                                        <span className="text-xs text-muted-foreground">
                                            {event.usernameAttempted
                                                ? `«${event.usernameAttempted}» — no existe`
                                                : 'Sin usuario'}
                                        </span>
                                    )}
                                </td>
                            )}

                            <td className="py-3 pr-5 text-sm text-muted-foreground">
                                {event.deviceLabel ?? '—'}
                            </td>

                            <td className="py-3 pr-5 font-mono text-sm text-muted-foreground tabular-nums">
                                {event.ipAddress ?? '—'}
                            </td>

                            <td className="py-3 pr-4 text-right">
                                <time
                                    className="font-mono text-xs text-muted-foreground tabular-nums"
                                    dateTime={event.occurredAt}
                                >
                                    {dateTime(event.occurredAt)}
                                </time>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
