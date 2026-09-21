import { ArrowRight, History, TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { dateTime } from '@/lib/format';

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

    return (
        <>
            <SheetHeader className="border-b">
                <SheetTitle>Historial {label}</SheetTitle>
                <SheetDescription>
                    Los cambios registrados, del más reciente al más antiguo.
                </SheetDescription>
            </SheetHeader>

            <div className="flex-1 space-y-3 px-4 py-4">
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
                    <>
                        <Skeleton className="h-20 w-full" />
                        <Skeleton className="h-20 w-full" />
                    </>
                )}

                {eventos?.length === 0 && (
                    <p className="flex items-start gap-2 rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                        <History
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        {SIN_CAMBIOS[subject]}
                    </p>
                )}

                {eventos?.map((e) => (
                    <div key={e.id} className="rounded-lg border p-3">
                        <p className="text-sm font-medium">
                            {ACCIONES[e.action] ?? e.action}
                        </p>
                        {/*
                         * `dateTime` y no `toLocaleString`: el instante se
                         * presenta en hora de Salta, no en la del navegador
                         * de quien mira.
                         */}
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {dateTime(e.at)} · {e.by ?? 'el sistema'}
                        </p>

                        {e.changes.length > 0 && (
                            <dl className="mt-2 space-y-1.5">
                                {e.changes.map((c) => (
                                    <div
                                        key={c.field}
                                        className="flex flex-wrap items-baseline gap-x-2 text-sm"
                                    >
                                        <dt className="min-w-24 text-xs text-field-label">
                                            {c.field}
                                        </dt>
                                        <dd className="flex min-w-0 items-center gap-2">
                                            {c.before !== null && (
                                                <>
                                                    <span className="break-words text-muted-foreground line-through">
                                                        {c.before}
                                                    </span>
                                                    <ArrowRight
                                                        className="size-3 shrink-0 text-muted-foreground"
                                                        aria-hidden="true"
                                                    />
                                                </>
                                            )}
                                            <span className="font-medium break-words">
                                                {c.after ?? '—'}
                                            </span>
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                    </div>
                ))}
            </div>
        </>
    );
}
