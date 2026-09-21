import { Link } from '@inertiajs/react';
import { Ban, ChevronDown, Lock, Maximize2, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import Money from '@/components/money';
import StatusBadge from '@/components/status-badge';
import type { StatusTone } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { EtiquetaOption } from '@/features/haberes/types';
import { date, dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { show as haberShow } from '@/routes/haberes/haber';
import HaberCancelDialog from './haber-cancel-dialog';
import InstallmentList from './installment-list';

type Haber = App.Modules.Haberes.Data.HaberListItemData;
type HaberStatus = App.Modules.Haberes.Enums.HaberWorkflowStatus;

const TONO: Record<HaberStatus, StatusTone> = {
    active: 'progress',
    suspended: 'action',
    blocked: 'blocked',
    cancelled: 'neutral',
    closed: 'done',
};

const ETIQUETA: Record<HaberStatus, string> = {
    active: 'Activo',
    suspended: 'Suspendido',
    blocked: 'Bloqueado',
    cancelled: 'Anulado',
    closed: 'Cerrado',
};

type Props = {
    haberes: Haber[];
    /**
     * En el listado alcanza con identificar a los beneficiarios. En el
     * detalle cada haber se puede desplegar para operar sobre sus cuotas.
     */
    mostrarCuotas?: boolean;
    etiquetas?: EtiquetaOption[];
    canEdit?: boolean;
    canCancel?: boolean;
    /**
     * Lo que se puede hacer sobre el conjunto, en el mismo renglón del
     * título. «Agregar haber» encabezaba la pantalla, junto a acciones que
     * son del expediente entero —editarlo, anularlo—; acá está donde mira
     * quien acaba de terminar de leer los haberes que ya hay.
     *
     * Solo lo usa el detalle: en la fila del listado no hay dónde ponerlo
     * ni tiene sentido ofrecerlo.
     */
    acciones?: ReactNode;
};

/** Los importes y las cuotas, iguales en el listado y en la tarjeta. */
function MontosDelHaber({
    haber,
    expandible = false,
    className,
}: {
    haber: Haber;
    /** La tarjeta muestra además concepto, cuotas y quién lo cargó. */
    expandible?: boolean;
    className?: string;
}) {
    const cargadas = haber.installments.length;

    return (
        <span
            className={cn(
                'grid grid-cols-2 gap-x-5 gap-y-2 text-left text-xs',
                /*
                 * Tres columnas y no dos: con `grid-cols-2`, «Cargado»
                 * caía a un renglón propio debajo de «Reconocido». Los
                 * tres datos del renglón se leen juntos o no se leen.
                 * En el teléfono siguen siendo dos y las fechas bajan.
                 */
                expandible ? 'sm:grid-cols-4 xl:grid-cols-6' : 'sm:grid-cols-3',
                className,
            )}
        >
            {expandible && (
                <span className="col-span-2 min-w-0 sm:col-span-1">
                    <span className="block text-[0.6875rem] tracking-wide text-field-label uppercase">
                        Concepto
                    </span>
                    <span
                        className="mt-0.5 block truncate text-foreground"
                        title={haber.concept ?? undefined}
                    >
                        {haber.concept ?? 'Sin concepto informado'}
                    </span>
                </span>
            )}

            <span>
                <span className="block text-[0.6875rem] tracking-wide text-field-label uppercase">
                    Reconocido
                </span>
                <Money
                    value={haber.assignedAmount}
                    className="mt-0.5 block text-foreground"
                />
            </span>

            <span>
                <span className="block text-[0.6875rem] tracking-wide text-field-label uppercase">
                    Financiado
                </span>
                <Money
                    value={haber.fundedAmount}
                    dimWhenZero
                    className="mt-0.5 block"
                />
            </span>

            {expandible && (
                <span className="col-span-2 sm:col-span-1">
                    <span className="block text-[0.6875rem] tracking-wide text-field-label uppercase">
                        Cuotas
                    </span>
                    <span className="mt-0.5 block text-foreground">
                        {cargadas} de {haber.installmentCount}{' '}
                        {cargadas === 1 ? 'cargada' : 'cargadas'}
                        <span className="text-muted-foreground">
                            {' '}
                            · {haber.paidInstallmentCount}{' '}
                            {haber.paidInstallmentCount === 1
                                ? 'pagada'
                                : 'pagadas'}
                        </span>
                    </span>
                </span>
            )}

            {/*
             * Quién cargó este haber, no el expediente: el haber llega meses
             * después y muchas veces lo carga otra persona.
             *
             * El autor solo cabe en la tarjeta; el renglón del listado se
             * queda con las dos fechas, que es lo que se escanea. La
             * modificación no se dibuja si no la hubo: repetir ahí la fecha
             * de carga diría que alguien tocó el haber el día que se creó.
             */}
            <span className="col-span-2 min-w-0 sm:col-span-1">
                <span className="block text-[0.6875rem] tracking-wide text-field-label uppercase">
                    Cargado
                </span>
                <span
                    className="mt-0.5 block text-foreground"
                    title={
                        haber.createdAt
                            ? `Cargado ${dateTime(haber.createdAt)}`
                            : undefined
                    }
                >
                    {haber.createdAt
                        ? expandible
                            ? dateTime(haber.createdAt)
                            : date(haber.createdAt)
                        : '—'}
                </span>
                {expandible && (
                    <span
                        className="block truncate text-muted-foreground"
                        title={haber.createdByName ?? undefined}
                    >
                        {haber.createdByName ?? 'Sin autor registrado'}
                    </span>
                )}
                {haber.lastChange && (
                    <span
                        className="block truncate text-muted-foreground"
                        title={
                            haber.lastChange.by
                                ? `Modificado ${dateTime(haber.lastChange.at)} por ${haber.lastChange.by}`
                                : `Modificado ${dateTime(haber.lastChange.at)}`
                        }
                    >
                        modif. {date(haber.lastChange.at)}
                        {expandible &&
                            haber.lastChange.by &&
                            ` · ${haber.lastChange.by}`}
                    </span>
                )}
            </span>

            {/*
             * El estado es una celda más y no una pieza aparte al costado.
             * Suelto tenía que elegir entre comerle ancho a los campos o
             * caer a un renglón propio; en la grilla se acomoda con ellos.
             */}
            {expandible && (
                <span className="col-span-2 sm:col-span-1">
                    <span className="block text-[0.6875rem] tracking-wide text-field-label uppercase">
                        Estado
                    </span>
                    <span className="mt-0.5 block">
                        <StatusBadge
                            label={ETIQUETA[haber.status]}
                            tone={TONO[haber.status]}
                        />
                    </span>
                </span>
            )}
        </span>
    );
}

/**
 * El haber como una fila del listado: identidad, estado e importes.
 *
 * La tarjeta del detalle arma su propio encabezado —tiene botones, y esos
 * no pueden ir adentro del disparador—, así que esto quedó para la fila,
 * que no los tiene.
 */
function HaberSummary({ haber }: { haber: Haber }) {
    return (
        <>
            <span className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                <span className="min-w-0 text-sm font-medium">
                    {haber.beneficiaryName}
                </span>
                <span className="font-mono text-xs text-muted-foreground tabular-nums">
                    DNI {haber.beneficiaryDocument}
                </span>

                <span className="ml-auto text-xs text-muted-foreground">
                    {haber.paidInstallmentCount} de {haber.installmentCount}{' '}
                    {haber.installmentCount === 1
                        ? 'cuota pagada'
                        : 'cuotas pagadas'}
                </span>

                <span className="shrink-0">
                    <StatusBadge
                        label={ETIQUETA[haber.status]}
                        tone={TONO[haber.status]}
                    />
                </span>
            </span>

            <MontosDelHaber haber={haber} className="mt-2" />
        </>
    );
}

function BlockReason({ reason }: { reason: string }) {
    return (
        <p className="mx-4 mb-3 flex items-start gap-1.5 rounded-md bg-destructive-soft px-2.5 py-2 text-xs text-destructive-strong">
            <Lock className="mt-px size-3 shrink-0" aria-hidden="true" />
            {reason}
        </p>
    );
}

function DetailedHaber({
    haber,
    defaultOpen,
    etiquetas,
    canEdit,
    canCancel,
    atenuado,
    onEditorChange,
}: {
    haber: Haber;
    defaultOpen: boolean;
    etiquetas: EtiquetaOption[];
    canEdit: boolean;
    /** Puede anular y reactivar este haber. */
    canCancel: boolean;
    /** Otro haber tiene el editor abierto: este pasa a segundo plano. */
    atenuado: boolean;
    onEditorChange: (abierto: boolean) => void;
}) {
    const [baja, setBaja] = useState(false);
    const anulado = haber.status === 'cancelled';

    return (
        <li
            className={cn(
                'overflow-hidden rounded-lg border bg-card shadow-xs transition-opacity duration-200',
                atenuado && 'opacity-40',
                // Un haber anulado se sigue mostrando, para que quede
                // registro de que estuvo, pero deja de pedir atención.
                anulado && 'border-dashed bg-muted/20',
            )}
        >
            {/*
             * `group` va en la raíz y no en un disparador: el chevron
             * vive en el segundo, y para girar necesita leer el estado que
             * escribe el primero.
             */}
            <Collapsible className="group" defaultOpen={defaultOpen}>
                {/*
                 * Dos renglones, y cada uno despliega. Son dos
                 * `CollapsibleTrigger` —el primitivo no guarda estado: lee
                 * el contexto y avisa— y eso es lo que deja meter los
                 * botones en el renglón del nombre sin anidarlos adentro
                 * de un botón.
                 */}
                <div className="p-2">
                    {/*
                     * Renglón de identidad, con lo que se puede hacer sobre
                     * este haber al final. Los botones son hermanos del
                     * disparador y no hijos: adentro serían un botón dentro
                     * de otro, que el analizador de HTML desarma.
                     */}
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <CollapsibleTrigger className="min-h-11 min-w-0 grow basis-full rounded-md px-2 py-1.5 text-left transition-colors outline-none hover:bg-muted/30 focus-visible:ring-2 focus-visible:ring-ring sm:basis-0">
                            <span className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                                <span className="min-w-0 text-sm font-medium">
                                    {haber.beneficiaryName}
                                </span>
                                <span className="font-mono text-xs text-muted-foreground tabular-nums">
                                    DNI {haber.beneficiaryDocument}
                                </span>
                            </span>
                        </CollapsibleTrigger>

                        <div className="flex shrink-0 flex-wrap justify-end gap-2 px-2 py-1.5">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="h-8 text-xs"
                                asChild
                            >
                                <Link
                                    href={haberShow([
                                        haber.expedienteId,
                                        haber.haberNumber,
                                    ])}
                                >
                                    <Maximize2
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />
                                    Ver el haber
                                </Link>
                            </Button>
                            {canCancel && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="h-8 text-xs"
                                    onClick={() => setBaja(true)}
                                >
                                    {anulado ? (
                                        <RotateCcw
                                            className="size-3.5"
                                            aria-hidden="true"
                                        />
                                    ) : (
                                        <Ban
                                            className="size-3.5"
                                            aria-hidden="true"
                                        />
                                    )}
                                    {anulado ? 'Reactivar' : 'Anular'}
                                </Button>
                            )}
                        </div>
                    </div>

                    {/*
                     * Renglón de importes, a todo el ancho: por eso el
                     * estado queda contra el borde de la tarjeta, justo
                     * debajo de los botones, y la grilla no se ahoga contra
                     * una columna fija en pantallas medianas.
                     *
                     * Al costado queda solo el chevron, que no es un dato
                     * sino la marca de que el renglón abre: por eso no se
                     * mueve de acá en ningún tamaño.
                     */}
                    <CollapsibleTrigger className="mt-1 flex w-full items-start gap-3 rounded-md px-2 py-1.5 text-left transition-colors outline-none hover:bg-muted/30 focus-visible:ring-2 focus-visible:ring-ring">
                        <MontosDelHaber
                            haber={haber}
                            expandible
                            className="min-w-0 flex-1"
                        />

                        <ChevronDown
                            className="size-4 shrink-0 text-muted-foreground transition-transform duration-200 group-data-[state=open]:rotate-180 motion-reduce:transition-none"
                            aria-hidden="true"
                        />
                    </CollapsibleTrigger>
                </div>

                {haber.blockReason && (
                    <BlockReason reason={haber.blockReason} />
                )}

                <CollapsibleContent className="overflow-hidden data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:slide-in-from-top-1 motion-reduce:animate-none">
                    <div className="border-t px-4 pt-3 pb-4">
                        {haber.notes && (
                            <p className="text-xs whitespace-pre-wrap text-muted-foreground">
                                {haber.notes}
                            </p>
                        )}

                        <InstallmentList
                            onEditorChange={onEditorChange}
                            expedienteId={haber.expedienteId}
                            haberNumber={haber.haberNumber}
                            cuotas={haber.installments}
                            previstas={haber.installmentCount}
                            totalReconocido={haber.assignedAmount}
                            etiquetas={etiquetas}
                            editable={canEdit && haber.status === 'active'}
                        />
                    </div>
                </CollapsibleContent>
            </Collapsible>

            {baja && (
                <HaberCancelDialog
                    haber={haber}
                    reactivar={anulado}
                    onClose={() => setBaja(false)}
                />
            )}
        </li>
    );
}

/**
 * Haberes reconocidos dentro del expediente.
 *
 * El detalle usa divulgación progresiva: el resumen conserva los datos que
 * se comparan entre beneficiarios y las cuotas aparecen al abrir uno. Así un
 * expediente con cinco personas sigue siendo recorrible sin perder contexto.
 */
export default function HaberList({
    haberes,
    mostrarCuotas = false,
    etiquetas = [],
    canEdit = false,
    canCancel = false,
    acciones,
}: Props) {
    /*
     * Qué haber tiene el editor abierto. Los demás pasan a segundo plano:
     * con cinco en pantalla, un formulario abierto en el tercero se pierde
     * entre todo lo que sigue encendido alrededor.
     */
    const [haberEnFoco, setHaberEnFoco] = useState<number | null>(null);

    const requiereAtencion = (haber: Haber) =>
        haber.status === 'blocked' ||
        haber.status === 'suspended' ||
        haber.installments.length < haber.installmentCount;
    const initiallyOpenId =
        haberes.length === 1
            ? haberes[0]?.id
            : (haberes.find(requiereAtencion)?.id ??
              haberes.find((haber) => haber.status === 'active')?.id ??
              haberes[0]?.id);

    if (mostrarCuotas) {
        return (
            <section aria-labelledby="haberes-reconocidos-title">
                <div className="mb-2 flex flex-wrap items-center gap-x-2 gap-y-1">
                    <h2
                        id="haberes-reconocidos-title"
                        className="text-sm font-semibold"
                    >
                        Haberes reconocidos
                    </h2>
                    <span className="text-xs text-muted-foreground">
                        {haberes.length}{' '}
                        {haberes.length === 1
                            ? 'beneficiario'
                            : 'beneficiarios'}
                    </span>
                    {acciones && <div className="ml-auto">{acciones}</div>}
                </div>

                <ul className="grid gap-2">
                    {haberes.map((haber) => (
                        <DetailedHaber
                            key={haber.id}
                            haber={haber}
                            defaultOpen={haber.id === initiallyOpenId}
                            etiquetas={etiquetas}
                            canEdit={canEdit}
                            canCancel={canCancel}
                            atenuado={
                                haberEnFoco !== null && haberEnFoco !== haber.id
                            }
                            onEditorChange={(abierto) =>
                                setHaberEnFoco(abierto ? haber.id : null)
                            }
                        />
                    ))}
                </ul>
            </section>
        );
    }

    return (
        <div className="px-5 py-4">
            <h4 className="mb-2 text-xs font-semibold tracking-wide text-primary uppercase">
                Haberes reconocidos
            </h4>

            <ul className="divide-y rounded-md border bg-card">
                {haberes.map((haber) => (
                    <li key={haber.id} className="px-4 py-3.5">
                        {/*
                         * El acceso al haber va ací y no solo en la fila del
                         * expediente: desplegado el acordeón, la pregunta
                         * pasó a ser por un beneficiario en particular, y
                         * obligar a entrar al expediente para volver a
                         * elegirlo es un rodeo.
                         */}
                        <div className="flex items-start gap-2">
                            <div className="min-w-0 flex-1">
                                <HaberSummary haber={haber} />
                            </div>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="shrink-0"
                                        asChild
                                        aria-label={`Ver el haber de ${haber.beneficiaryName}`}
                                    >
                                        <Link
                                            href={haberShow([
                                                haber.expedienteId,
                                                haber.haberNumber,
                                            ])}
                                        >
                                            <Maximize2
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                        </Link>
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>Ver el haber</TooltipContent>
                            </Tooltip>
                        </div>
                        {haber.blockReason && (
                            <div className="-mx-4 mt-3 -mb-3.5">
                                <BlockReason reason={haber.blockReason} />
                            </div>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}
