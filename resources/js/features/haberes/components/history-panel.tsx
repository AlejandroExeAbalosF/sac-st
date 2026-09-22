import { Check, History, RotateCcw, TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import type { AuditChange } from '@/features/auditoria/components/change-list';
import ChangeList from '@/features/auditoria/components/change-list';
import { dateTime, DISPLAY_TIME_ZONE } from '@/lib/format';
import { cn } from '@/lib/utils';

/**
 * Un evento, con su rótulo ya traducido.
 *
 * El rótulo viene del catálogo de auditoría del servidor. Antes había acá
 * un mapa propio que se desfasó: traducía códigos que el servidor no
 * emitía y dejaba en crudo otros que sí.
 */
type Evento = {
    id: number;
    action: string;
    label: string;
    critical: boolean;
    at: string;
    by: string | null;
    changes: AuditChange[];
};

export type HistorySubject = 'installment' | 'expediente' | 'haber';

/**
 * Que no haya nada es una respuesta, y conviene que se lea como tal.
 *
 * Un haber casi siempre va a estar vacío: se reconoce, se anula y se
 * reactiva, pero no se corrige —lo que se corrige son sus cuotas—.
 */
const SIN_CAMBIOS: Record<HistorySubject, string> = {
    installment: 'Esta cuota no se modificó desde que se cargó.',
    expediente: 'Este expediente no se modificó desde que se cargó.',
    haber: 'Este haber no se modificó desde que se reconoció.',
};

type EventoDelDia = Evento & { hora: string };
type DiaDeActividad = {
    fecha: string;
    titulo: string;
    eventos: EventoDelDia[];
};

const FECHA_DE_ACTIVIDAD = new Intl.DateTimeFormat('es-AR', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    timeZone: DISPLAY_TIME_ZONE,
});

function agruparPorDia(eventos: Evento[]): DiaDeActividad[] {
    const dias: DiaDeActividad[] = [];

    for (const evento of eventos) {
        const fechaLocal = dateTime(evento.at);
        const fecha = fechaLocal.slice(0, 10);
        const ultimo = dias.at(-1);

        if (ultimo?.fecha !== fecha) {
            dias.push({
                fecha,
                titulo: FECHA_DE_ACTIVIDAD.format(new Date(evento.at)),
                eventos: [],
            });
        }

        dias.at(-1)?.eventos.push({ ...evento, hora: fechaLocal.slice(-5) });
    }

    return dias;
}

/**
 * El historial de una cuota: qué cambió, quién y cuándo.
 *
 * El rastro se venía guardando desde el principio y no había dónde leerlo.
 * Un registro que nadie puede consultar no es auditoría: es un archivo que
 * crece.
 *
 * **Se muestra el antes y el después**, no solo que «hubo un cambio»: lo
 * dibuja `ChangeList`, el mismo que usa la auditoría de operaciones.
 *
 * Vive en el panel lateral y ya no en un diálogo: la pregunta aparece
 * mirando la cosa, y un diálogo modal tapaba justo lo que se estaba
 * mirando para contestarla.
 *
 * **El mismo componente sirve a los tres sujetos** —cuota, haber y
 * expediente— porque los tres endpoints devuelven la misma forma, que
 * `AuditTimeline` define una sola vez del lado de PHP. Lo único que
 * cambia es a qué dirección pedirle y cómo se llama lo que se está
 * mirando.
 */
export default function HistoryPanel({
    subject,
    url,
    label,
}: {
    subject: HistorySubject;
    /**
     * La dirección del historial, ya armada por quien abre el panel.
     *
     * Las tres rutas no tienen la misma forma —la del haber va anidada
     * bajo su expediente, porque su ordinal solo es único ahí adentro— y
     * rearmarlas acá obligaría a este componente a conocer la clave de
     * ruta de cada modelo.
     */
    url: string;
    /**
     * Cómo sigue el título después de «Historial», con su preposición ya
     * contraída: «del expediente 125959/2026», «de la cuota 1».
     *
     * Viaja entero y no como sustantivo suelto porque en castellano el
     * artículo depende del género y «de + el» se contrae. Armarlo acá
     * pedía una tabla de géneros para ganar nada.
     */
    label: string;
}) {
    const [eventos, setEventos] = useState<Evento[] | null>(null);
    const [falló, setFalló] = useState(false);

    /*
     * Sin reset al empezar: el host remonta este componente por `key`
     * cuando cambia el sujeto, así que arranca siempre en blanco.
     */
    useEffect(() => {
        let vigente = true;

        fetch(url, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => {
                if (!r.ok) {
                    throw new Error(String(r.status));
                }

                return r.json();
            })
            .then((d: { events: Evento[] }) => vigente && setEventos(d.events))
            .catch(() => vigente && setFalló(true));

        return () => {
            vigente = false;
        };
    }, [url]);

    const dias = eventos === null ? [] : agruparPorDia(eventos);

    return (
        <>
            <SheetHeader className="border-b bg-muted/30 pr-12">
                <SheetTitle className="text-lg leading-tight">
                    Historial {label}
                </SheetTitle>
                <SheetDescription>
                    {falló
                        ? 'Actividad y cambios registrados'
                        : eventos === null
                          ? 'Cargando actividad…'
                          : eventos.length === 0
                            ? '0 registros'
                            : `${eventos.length} ${eventos.length === 1 ? 'registro' : 'registros'} · más reciente primero`}
                </SheetDescription>
            </SheetHeader>

            <div className="flex-1 px-5 py-4">
                {falló && (
                    <p className="flex items-start gap-2 rounded-md border border-current bg-destructive-soft p-3 text-sm text-destructive-strong">
                        <TriangleAlert
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        No se pudo traer el historial. Cerrá el panel y volvé a
                        intentarlo.
                    </p>
                )}

                {!falló && eventos === null && (
                    <div className="space-y-5" aria-label="Cargando historial">
                        <Skeleton className="h-3 w-40" />
                        <div className="space-y-5 border-l pl-6">
                            <Skeleton className="h-16 w-full" />
                            <Skeleton className="h-16 w-5/6" />
                        </div>
                    </div>
                )}

                {eventos?.length === 0 && (
                    <div className="py-10 text-center">
                        <span className="mx-auto grid size-10 place-items-center rounded-full bg-muted text-muted-foreground">
                            <History className="size-5" aria-hidden="true" />
                        </span>
                        <p className="mx-auto mt-3 max-w-60 text-sm text-muted-foreground">
                            {SIN_CAMBIOS[subject]}
                        </p>
                    </div>
                )}

                {dias.map((dia, indiceDeDia) => (
                    <section key={dia.fecha} className="mb-5 last:mb-0">
                        <h3 className="mb-3 flex items-center gap-3 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            {dia.titulo}
                            <span
                                className="h-px min-w-4 flex-1 bg-border"
                                aria-hidden="true"
                            />
                        </h3>
                        <ol className="ml-2 border-l border-border">
                            {dia.eventos.map((e, indice) => {
                                const cancelado =
                                    /\.(cancelado|anulado|anulada)$/.test(
                                        e.action,
                                    );
                                const acreditado =
                                    e.action.endsWith('.acreditado');
                                const reciente =
                                    indiceDeDia === 0 && indice === 0;

                                return (
                                    <li
                                        key={e.id}
                                        className="relative pb-4 pl-6 last:pb-0"
                                    >
                                        <span
                                            className={cn(
                                                'absolute top-0 -left-[9px] grid size-[18px] place-items-center rounded-full ring-4 ring-background',
                                                cancelado
                                                    ? 'bg-warning-soft text-warning-strong'
                                                    : acreditado
                                                      ? 'bg-success-soft text-success-strong'
                                                      : 'bg-primary text-primary-foreground',
                                            )}
                                            aria-hidden="true"
                                        >
                                            {cancelado ? (
                                                <RotateCcw className="size-3" />
                                            ) : acreditado ? (
                                                <Check className="size-3" />
                                            ) : (
                                                <span className="size-1.5 rounded-full bg-current" />
                                            )}
                                        </span>

                                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                                            <time
                                                dateTime={e.at}
                                                className="font-semibold text-primary tabular-nums"
                                            >
                                                {e.hora}
                                            </time>
                                            <span className="text-muted-foreground">
                                                · {e.by ?? 'el sistema'}
                                            </span>
                                            {reciente && (
                                                <span className="rounded-full bg-primary/10 px-2 py-0.5 font-medium text-primary">
                                                    Más reciente
                                                </span>
                                            )}
                                        </div>
                                        <p className="mt-1 text-sm leading-snug font-semibold text-foreground">
                                            {e.label}
                                        </p>

                                        <ChangeList
                                            changes={e.changes}
                                            className="mt-2"
                                        />
                                    </li>
                                );
                            })}
                        </ol>
                    </section>
                ))}
            </div>
        </>
    );
}
