import { Link } from '@inertiajs/react';
import { ChevronRight, ShieldAlert } from 'lucide-react';
import { Fragment, useState } from 'react';
import ChangeList from '@/features/auditoria/components/change-list';
import { dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';

type Evento = App.Modules.Shared.Data.OperationAuditEventData;

/**
 * Los eventos de la auditoría de operaciones, en tabla.
 *
 * Cada fila dice qué pasó, sobre qué y quién; el antes y el después se
 * abren a pedido, porque cincuenta eventos con todos sus campos a la vista
 * no se leen como una lista.
 *
 * Una acción crítica lleva un filete al costado y su distintivo, como los
 * accesos sospechosos en la otra pantalla: el ojo las encuentra sin
 * filtrar y la tabla sigue leyéndose de corrido.
 *
 * El número de evento está a la vista porque es la llave para buscarlo en
 * el registro de acceso HTTP (`audit_id`).
 */
export default function OperationTable({
    events,
    emptyLabel,
}: {
    events: Evento[];
    emptyLabel: string;
}) {
    const [abiertos, setAbiertos] = useState<ReadonlySet<number>>(new Set());

    const alternar = (id: number) =>
        setAbiertos((previo) => {
            const siguiente = new Set(previo);

            if (siguiente.has(id)) {
                siguiente.delete(id);
            } else {
                siguiente.add(id);
            }

            return siguiente;
        });

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
                <caption className="sr-only">Auditoría</caption>
                <thead className="sticky top-0 z-10">
                    <tr className="border-b bg-muted text-xs tracking-wide text-field-label uppercase shadow-[0_1px_0_0_var(--border)]">
                        <th scope="col" className="w-10 px-2 py-2.5">
                            <span className="sr-only">Detalle</span>
                        </th>
                        <th scope="col" className="py-2.5 pr-5 font-medium">
                            Cuándo
                        </th>
                        <th scope="col" className="py-2.5 pr-5 font-medium">
                            Acción
                        </th>
                        <th scope="col" className="py-2.5 pr-5 font-medium">
                            Sobre
                        </th>
                        <th scope="col" className="py-2.5 pr-5 font-medium">
                            Usuario
                        </th>
                        <th
                            scope="col"
                            className="py-2.5 pr-4 text-right font-medium"
                        >
                            N.º
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {events.map((evento) => {
                        const abierto = abiertos.has(evento.id);
                        const detalle = `detalle-${evento.id}`;
                        const tieneDetalle =
                            evento.changes.length > 0 ||
                            evento.ipAddress !== null;

                        return (
                            <Fragment key={evento.id}>
                                <tr
                                    className={cn(
                                        'border-b align-top transition-colors hover:bg-accent/30',
                                        evento.critical &&
                                            'shadow-[inset_3px_0_0_0_var(--warning)]',
                                        abierto && 'border-b-0 bg-accent/20',
                                    )}
                                >
                                    <td className="px-2 py-3">
                                        {tieneDetalle && (
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    alternar(evento.id)
                                                }
                                                aria-expanded={abierto}
                                                aria-controls={detalle}
                                                className="grid size-6 place-items-center rounded text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                            >
                                                <ChevronRight
                                                    className={cn(
                                                        'size-4 transition-transform',
                                                        abierto && 'rotate-90',
                                                    )}
                                                    aria-hidden="true"
                                                />
                                                <span className="sr-only">
                                                    {abierto
                                                        ? 'Ocultar el detalle'
                                                        : 'Ver el detalle'}
                                                </span>
                                            </button>
                                        )}
                                    </td>
                                    <td className="py-3 pr-5 text-sm whitespace-nowrap tabular-nums">
                                        <time dateTime={evento.occurredAt}>
                                            {dateTime(evento.occurredAt)}
                                        </time>
                                    </td>
                                    <td className="py-3 pr-5">
                                        <p className="text-sm font-medium">
                                            {evento.label}
                                        </p>
                                        <p className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                                            {evento.category}
                                            {evento.critical && (
                                                <span className="inline-flex items-center gap-1 rounded-full bg-warning-soft px-2 py-0.5 font-medium text-warning-strong">
                                                    <ShieldAlert
                                                        className="size-3"
                                                        aria-hidden="true"
                                                    />
                                                    Crítica
                                                </span>
                                            )}
                                        </p>
                                    </td>
                                    <td className="py-3 pr-5">
                                        <p className="text-xs text-muted-foreground">
                                            {evento.subjectLabel}
                                        </p>
                                        {evento.subjectUrl === null ? (
                                            <p className="text-sm">
                                                {evento.subjectDescription}
                                            </p>
                                        ) : (
                                            <Link
                                                href={evento.subjectUrl}
                                                className="text-sm text-primary underline-offset-4 hover:underline"
                                            >
                                                {evento.subjectDescription}
                                            </Link>
                                        )}
                                    </td>
                                    <td className="py-3 pr-5 text-sm">
                                        {evento.userName ?? (
                                            <span className="text-muted-foreground italic">
                                                Sistema
                                            </span>
                                        )}
                                    </td>
                                    <td className="py-3 pr-4 text-right font-mono text-xs text-muted-foreground tabular-nums">
                                        {evento.id}
                                    </td>
                                </tr>
                                {abierto && tieneDetalle && (
                                    <tr
                                        id={detalle}
                                        className={cn(
                                            'border-b bg-accent/20',
                                            evento.critical &&
                                                'shadow-[inset_3px_0_0_0_var(--warning)]',
                                        )}
                                    >
                                        <td />
                                        <td colSpan={5} className="pr-4 pb-3">
                                            <ChangeList
                                                changes={evento.changes}
                                                className="max-w-2xl bg-background"
                                            />
                                            {evento.ipAddress !== null && (
                                                <p className="mt-1.5 text-xs text-muted-foreground">
                                                    Desde{' '}
                                                    <span className="font-mono tabular-nums">
                                                        {evento.ipAddress}
                                                    </span>
                                                </p>
                                            )}
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
