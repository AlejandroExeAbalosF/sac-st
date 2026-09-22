import {
    ArrowRight,
    Check,
    History,
    RotateCcw,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { dateTime, DISPLAY_TIME_ZONE } from '@/lib/format';
import { cn } from '@/lib/utils';

type Cambio = {
    field: string;
    before: string | null;
    after: string | null;
};

type Evento = {
    id: number;
    action: string;
    at: string;
    by: string | null;
    changes: Cambio[];
};

/**
 * Qué dice cada acción, en palabras del área.
 *
 * Un solo mapa para los tres sujetos: los nombres vienen con su prefijo,
 * así que no se pisan, y tenerlos juntos evita que la misma acción se
 * traduzca distinto según desde dónde se abra el panel.
 */
const ACCIONES: Record<string, string> = {
    'cuota.corregida': 'Se corrigió la cuota',
    'cuota.creada': 'Se cargó la cuota',
    'cuota.anulada': 'Se anuló la cuota',
    'cuota.reactivada': 'Se reactivó la cuota',
    'cuota.medio-alineado':
        'Se alineó el medio con el comprobante bancario cargado',
    // Del efectivo de esta cuota, camino al banco.
    'traslado.depositado': 'Se depositó el efectivo en el banco',
    'traslado.acreditado': 'Se acreditó el depósito en el extracto',
    'traslado.cancelado':
        'Se canceló el depósito y el efectivo volvió a la caja',
    // Del haber.
    'haber.reconocido': 'Se reconoció el haber',
    'haber.anulado': 'Se anuló el haber',
    'haber.reactivado': 'Se reactivó el haber',
    // Del expediente.
    'expediente.registrado': 'Se registró el expediente',
    'expediente.corregido': 'Se corrigió la ficha del expediente',
    'expediente.anulado': 'Se anuló el expediente',
    'expediente.reactivado': 'Se reactivó el expediente',
    'expediente.fecha-ingreso-corregida': 'Se corrigió la fecha de ingreso',
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
 * **Se muestra el antes y el después**, no solo que «hubo un cambio». La
 * pregunta real nunca es si alguien tocó la cuota, es qué decía antes.
 *
 * Salvo cuando no hubo un antes. Un alta o un depósito no corrigen nada, y
 * dibujarlos como «— → valor», con la raya tachada, hacía leer un cambio
 * donde solo hay un hecho.
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
                                            {ACCIONES[e.action] ?? e.action}
                                        </p>

                                        {e.changes.length > 0 && (
                                            <dl className="mt-2 divide-y divide-border/60 rounded-md bg-muted/45 px-3 py-1">
                                                {e.changes.map((c) => (
                                                    <div
                                                        key={c.field}
                                                        className="grid grid-cols-[minmax(0,6.5rem)_minmax(0,1fr)] gap-x-2 py-1 text-sm"
                                                    >
                                                        <dt className="text-xs leading-5 text-field-label">
                                                            {c.field}
                                                        </dt>
                                                        <dd className="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-0.5 leading-5">
                                                            {c.before !==
                                                                null && (
                                                                <>
                                                                    <span className="min-w-0 break-words text-muted-foreground line-through">
                                                                        {
                                                                            c.before
                                                                        }
                                                                    </span>
                                                                    <ArrowRight
                                                                        className="size-3 shrink-0 text-muted-foreground"
                                                                        aria-hidden="true"
                                                                    />
                                                                </>
                                                            )}
                                                            <span className="min-w-0 font-medium break-words">
                                                                {c.after ?? '—'}
                                                            </span>
                                                        </dd>
                                                    </div>
                                                ))}
                                            </dl>
                                        )}
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
