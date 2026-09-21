import { Link } from '@inertiajs/react';
import { ArrowRight, Lock } from 'lucide-react';
import StatusBadge from '@/components/status-badge';
import type { StatusTone } from '@/components/status-badge';
import { cn } from '@/lib/utils';

type WorkQueue = App.Modules.Shared.Data.WorkQueueData;
type QueueTone = App.Modules.Shared.Enums.QueueTone;

/**
 * Tratamiento de cada estado.
 *
 * El tipo del índice es el enum generado desde PHP: si alguien agrega un
 * caso a `QueueTone` y no lo contempla acá, TypeScript rompe la
 * compilación. Es el punto donde el tipado extremo a extremo deja de ser
 * una promesa y se vuelve una red.
 */
const TONO: Record<
    QueueTone,
    { numero: string; etiqueta: string; badge: StatusTone }
> = {
    neutral: {
        numero: 'text-foreground',
        etiqueta: 'En seguimiento',
        badge: 'neutral',
    },
    action: {
        numero: 'text-warning-strong',
        etiqueta: 'Pendiente',
        badge: 'action',
    },
    blocked: {
        numero: 'text-destructive-strong',
        etiqueta: 'Bloqueado',
        badge: 'blocked',
    },
    done: {
        numero: 'text-success-strong',
        etiqueta: 'Al día',
        badge: 'done',
    },
};

/**
 * Una cola de trabajo.
 *
 * La tarjeta tiene tres estados y cada uno dice algo distinto:
 *
 * - **sin datos** (`count === null`): el módulo que la alimenta no existe;
 * - **en cero**: no hay nada pendiente, y eso es información;
 * - **con número**: hay trabajo, y desde acá se llega a él.
 *
 * Cuando existe una pantalla que muestra exactamente esas filas, la
 * tarjeta entera es el enlace. Cuando no —el estado de una Orden vive
 * adentro del expediente y ningún listado lo filtra—, trae los primeros
 * casos y cada uno es su propio enlace. Lo que nunca hace es mandar al
 * listado completo: eso sería devolver la pregunta.
 */
export default function QueueCard({ queue }: { queue: WorkQueue }) {
    const tono = TONO[queue.tone];
    const disponible = queue.count !== null;
    const vacia = queue.count === 0;

    const cuerpo = (
        <>
            {disponible ? (
                <div className="flex items-start justify-between gap-3">
                    <p className="flex items-baseline gap-1.5">
                        <span
                            className={cn(
                                'font-mono text-3xl leading-none font-semibold tabular-nums',
                                vacia ? 'text-muted-foreground' : tono.numero,
                            )}
                        >
                            {queue.count}
                        </span>
                        {queue.hasMore && (
                            <span className="text-sm font-medium text-muted-foreground">
                                o más
                            </span>
                        )}
                    </p>
                    <StatusBadge label={tono.etiqueta} tone={tono.badge} />
                </div>
            ) : (
                <div className="flex items-center justify-between gap-3">
                    <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                        <Lock
                            className="size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        Sin datos todavía
                    </p>
                    <StatusBadge label="No disponible" />
                </div>
            )}

            <h4 className="mt-2 flex items-center gap-1.5 text-sm font-semibold">
                {queue.title}
                {queue.href && !vacia && (
                    <ArrowRight
                        className="size-3.5 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                        aria-hidden="true"
                    />
                )}
            </h4>
            <p className="mt-0.5 text-xs text-muted-foreground">
                {queue.description}
            </p>

            {!disponible && queue.pendingModule && (
                <p className="mt-3 border-t border-dashed pt-2 text-xs text-muted-foreground">
                    Depende del módulo{' '}
                    <span className="font-medium text-foreground">
                        {queue.pendingModule}
                    </span>
                    , todavía no construido.
                </p>
            )}
        </>
    );

    const clases = cn(
        'block overflow-hidden rounded-lg border bg-card p-5 shadow-raised',
        !disponible && 'border-dashed',
    );

    /*
     * El enlace se ofrece solo si hay algo del otro lado. Una tarjeta en
     * cero que igual navega lleva a un listado vacío, y eso se siente como
     * un error del sistema y no como la buena noticia que es.
     */
    if (queue.href && !vacia && disponible) {
        return (
            <Link
                href={queue.href}
                className={cn(
                    clases,
                    'group transition-colors hover:border-primary/40 hover:bg-accent/40',
                    'focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                )}
            >
                {cuerpo}
            </Link>
        );
    }

    return (
        <article className={clases}>
            {cuerpo}

            {queue.samples.length > 0 && (
                <ul className="mt-3 space-y-1 border-t border-dashed pt-2">
                    {queue.samples.map((sample) => (
                        <li key={sample.href}>
                            <Link
                                href={sample.href}
                                className="group flex items-baseline gap-1.5 text-xs hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <span className="truncate font-medium">
                                    {sample.label}
                                </span>
                                {sample.detail && (
                                    <span className="shrink-0 text-muted-foreground">
                                        {sample.detail}
                                    </span>
                                )}
                            </Link>
                        </li>
                    ))}
                    {queue.count !== null &&
                        queue.count > queue.samples.length && (
                            <li className="text-xs text-muted-foreground">
                                y {queue.count - queue.samples.length} más
                            </li>
                        )}
                </ul>
            )}
        </article>
    );
}
