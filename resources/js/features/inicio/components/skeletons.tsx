import { Skeleton } from '@/components/ui/skeleton';

/**
 * Los esqueletos del tablero.
 *
 * No son adorno: las colas, los saldos y los movimientos llegan diferidos
 * con `Inertia::defer()`, así que hay una espera real que tapar. Cada
 * esqueleto **copia la geometría de su bloque** —misma grilla, misma
 * altura de fila, mismo padding— para que la llegada de los datos no
 * mueva nada de lugar: un salto de layout obliga a volver a leer la
 * pantalla entera.
 *
 * La animación sale de `ui/skeleton`, que ya trae `animate-pulse` con
 * `motion-reduce:animate-none`: quien pidió menos movimiento en su
 * sistema operativo ve los bloques quietos, no una pantalla que late.
 */

export function QueueGridSkeleton() {
    const groups = [1, 4, 1];

    return (
        <div
            className="space-y-6"
            role="status"
            aria-label="Cargando colas de trabajo"
        >
            {groups.map((count, group) => (
                <div key={group}>
                    <div className="mb-2 flex items-center gap-3">
                        <Skeleton className="h-4 w-24" />
                        <Skeleton className="h-3 w-56" />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {Array.from({ length: count }, (_, i) => (
                            <div
                                key={i}
                                className="overflow-hidden rounded-lg border bg-card p-5 shadow-raised"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <Skeleton className="h-8 w-16" />
                                    <Skeleton className="h-5 w-20 rounded-full" />
                                </div>
                                <Skeleton className="mt-3 h-4 w-40" />
                                <Skeleton className="mt-2 h-3 w-full max-w-56" />
                            </div>
                        ))}
                    </div>
                </div>
            ))}
        </div>
    );
}

export function CashDaySkeleton() {
    return (
        <div
            className="rounded-lg border bg-card shadow-raised"
            role="status"
            aria-label="Cargando estado de la caja"
        >
            <div className="flex items-center gap-2 border-b px-5 py-4">
                <Skeleton className="h-4 w-32" />
                <Skeleton className="ml-auto h-5 w-20 rounded-full" />
            </div>
            <dl className="grid gap-px bg-border sm:grid-cols-4">
                {Array.from({ length: 4 }, (_, i) => (
                    <div key={i} className="bg-card px-5 py-4">
                        <Skeleton className="h-3 w-24" />
                        <Skeleton className="mt-2 h-6 w-28" />
                    </div>
                ))}
            </dl>
        </div>
    );
}

export function CashBoxSummarySkeleton() {
    return (
        <dl
            className="grid gap-px overflow-hidden rounded-lg border bg-border sm:grid-cols-3"
            role="status"
            aria-label="Cargando volumen por caja"
        >
            {Array.from({ length: 3 }, (_, i) => (
                <div key={i} className="bg-card px-5 py-4">
                    <Skeleton className="h-3 w-20" />
                    <Skeleton className="mt-2 h-6 w-12" />
                    <Skeleton className="mt-3 h-1 w-full rounded-full" />
                </div>
            ))}
        </dl>
    );
}

/** Filas de alto fijo: sirve para movimientos y para cualquier lista del tablero. */
export function ListSkeleton({ rows = 5 }: { rows?: number }) {
    return (
        <ul
            className="divide-y"
            role="status"
            aria-label="Cargando movimientos"
        >
            {Array.from({ length: rows }, (_, i) => (
                <li key={i} className="flex items-center gap-3 px-5 py-3.5">
                    <Skeleton className="size-4 shrink-0 rounded-full" />
                    <div className="min-w-0 flex-1">
                        <Skeleton className="h-4 w-44" />
                        <Skeleton className="mt-1.5 h-3 w-28" />
                    </div>
                    <Skeleton className="h-4 w-24 shrink-0" />
                </li>
            ))}
        </ul>
    );
}

/**
 * Lo que se ve cuando la consulta de un bloque falla.
 *
 * Va en el slot `rescue` de `<Deferred>`: el bloque avisa y el resto del
 * tablero sigue sirviendo. Una pantalla entera caída porque un saldo no
 * se pudo calcular sería peor que el saldo faltante.
 */
export function BlockUnavailable({ what }: { what: string }) {
    return (
        <p
            role="alert"
            className="rounded-lg border border-dashed bg-muted/40 px-5 py-8 text-center text-sm text-muted-foreground"
        >
            No se pudo cargar {what}. Recargá la página para volver a
            intentarlo.
        </p>
    );
}
