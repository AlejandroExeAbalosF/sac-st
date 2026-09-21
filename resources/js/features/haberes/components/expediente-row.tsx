import { Link } from '@inertiajs/react';
import { ChevronRight, Eye, Users } from 'lucide-react';
import { useId, useState } from 'react';
import Money from '@/components/money';
import StatusBadge from '@/components/status-badge';
import type { StatusTone } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { date } from '@/lib/format';
import { cn } from '@/lib/utils';
import { show } from '@/routes/expedientes';
import HaberList from './haber-list';
import RecordDates from './record-dates';

type Expediente = App.Modules.Haberes.Data.ExpedienteListItemData;
type ExpedienteStatus = App.Modules.Haberes.Enums.ExpedienteStatus;

const TONO_EXPEDIENTE: Record<ExpedienteStatus, StatusTone> = {
    active: 'progress',
    suspended: 'action',
    closed: 'done',
    cancelled: 'neutral',
};

const ETIQUETA_EXPEDIENTE: Record<ExpedienteStatus, string> = {
    active: 'Activo',
    suspended: 'Suspendido',
    closed: 'Cerrado',
    cancelled: 'Anulado',
};

/**
 * Fila de expediente que se despliega para mostrar sus haberes.
 *
 * El acordeón responde sin navegar la pregunta más frecuente al recorrer
 * la lista —«¿quiénes son los beneficiarios?»— y mantiene el listado
 * escaneable. No reemplaza a la pantalla de detalle: las cuotas, los
 * recibos y las Órdenes viven ahí, porque meterlas también acá haría
 * crecer la fila sin límite.
 */
export default function ExpedienteRow({
    expediente,
}: {
    expediente: Expediente;
}) {
    const [abierto, setAbierto] = useState(false);
    const panelId = useId();
    const cantidad = expediente.haberes.length;
    const primero = expediente.haberes[0];

    return (
        <>
            {/*
             * El resalte al pasar el mouse y al enfocar con el teclado es lo
             * que sostiene la lectura horizontal: seguir una fila de siete
             * columnas sin ninguna referencia es donde se pierde el ojo.
             * `focus-within` lo replica para quien navega con Tab, que de
             * otro modo se quedaría sin la ayuda.
             */}
            <tr
                className={cn(
                    'border-b transition-colors',
                    abierto
                        ? 'bg-accent/50'
                        : 'focus-within:bg-accent/30 hover:bg-accent/30',
                )}
            >
                {/*
                 * Riel a la izquierda mientras está desplegada: ata
                 * visualmente la fila madre con el panel de haberes que
                 * aparece debajo, que si no queda flotando.
                 */}
                <td
                    className={cn(
                        'py-3 pr-2 pl-3 align-middle',
                        abierto && 'shadow-[inset_3px_0_0_0_var(--primary)]',
                    )}
                >
                    <button
                        type="button"
                        onClick={() => setAbierto((v) => !v)}
                        aria-expanded={abierto}
                        aria-controls={panelId}
                        className="flex items-center gap-1.5 rounded-md px-1.5 py-1 text-left hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <ChevronRight
                            className={cn(
                                'size-4 shrink-0 text-muted-foreground transition-transform',
                                abierto && 'rotate-90',
                            )}
                            aria-hidden="true"
                        />
                        <span className="font-mono text-sm font-medium tabular-nums">
                            {expediente.displayNumber}
                        </span>
                        <span className="sr-only">
                            {abierto ? 'Ocultar' : 'Ver'} los {cantidad} haberes
                            del expediente
                        </span>
                    </button>
                </td>

                {/*
                 * Antes acá iba la carátula, y decía lo mismo dos veces:
                 * «García Claudio Adrián c/ CIACSA» es el beneficiario que
                 * ya se puede nombrar acá y el empleador que está en la
                 * columna siguiente. El nombre solo es más corto, se lee
                 * derecho y es por lo que se busca.
                 */}
                <td className="py-3 pr-5">
                    {primero === undefined ? (
                        <p className="text-sm text-muted-foreground italic">
                            Sin haberes cargados
                        </p>
                    ) : (
                        <p className="flex items-baseline gap-1.5 text-sm">
                            <span className="truncate">
                                {primero.beneficiaryName}
                            </span>
                            {cantidad > 1 && (
                                <span
                                    className="shrink-0 rounded bg-muted px-1.5 py-0.5 text-[0.65rem] font-medium text-muted-foreground"
                                    title={`${cantidad} beneficiarios en este expediente`}
                                >
                                    <Users
                                        className="mr-0.5 inline size-2.5"
                                        aria-hidden="true"
                                    />
                                    +{cantidad - 1}
                                </span>
                            )}
                        </p>
                    )}
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {primero?.beneficiaryDocument && (
                            <span className="font-mono tabular-nums">
                                {primero.beneficiaryDocument}
                            </span>
                        )}
                        {primero?.beneficiaryDocument &&
                            expediente.receivedDate &&
                            ' · '}
                        {expediente.receivedDate &&
                            `recibido ${date(expediente.receivedDate)}`}
                    </p>
                </td>

                <td className="py-3 pr-5 text-sm">{expediente.employerName}</td>

                <td className="py-3 pr-5 text-right text-sm">
                    <Money value={expediente.recognizedTotalAmount} />
                </td>

                <td className="py-3 pr-5 text-right text-sm">
                    <Money value={expediente.fundedTotalAmount} dimWhenZero />
                </td>

                <td className="py-3 pr-5">
                    <StatusBadge
                        label={ETIQUETA_EXPEDIENTE[expediente.status]}
                        tone={TONO_EXPEDIENTE[expediente.status]}
                    />
                </td>

                <td className="py-3 pr-5">
                    <RecordDates
                        createdAt={expediente.createdAt}
                        lastChange={expediente.lastChange}
                    />
                </td>

                <td className="py-3 pr-4">
                    <div className="flex justify-end">
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    asChild
                                    aria-label={`Ver el expediente ${expediente.displayNumber}`}
                                >
                                    <Link href={show(expediente.id)}>
                                        <Eye
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                    </Link>
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>Ver el expediente</TooltipContent>
                        </Tooltip>
                    </div>
                </td>
            </tr>

            {abierto && (
                <tr id={panelId}>
                    <td
                        colSpan={8}
                        className="border-b bg-accent/25 p-0 shadow-[inset_3px_0_0_0_var(--primary)]"
                    >
                        <HaberList haberes={expediente.haberes} />
                    </td>
                </tr>
            )}
        </>
    );
}

/** La misma lectura del expediente, reordenada para una pantalla angosta. */
export function ExpedienteCard({ expediente }: { expediente: Expediente }) {
    const [abierto, setAbierto] = useState(false);
    const panelId = useId();
    const cantidad = expediente.haberes.length;
    const primero = expediente.haberes[0];

    return (
        <article className="overflow-hidden rounded-lg border bg-card shadow-xs">
            <button
                type="button"
                onClick={() => setAbierto((valor) => !valor)}
                aria-expanded={abierto}
                aria-controls={panelId}
                className="group flex min-h-11 w-full items-start justify-between gap-3 px-4 py-3.5 text-left transition-colors outline-none hover:bg-muted/30 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
            >
                <span className="min-w-0">
                    <span className="flex items-center gap-2">
                        <span className="font-mono text-sm font-semibold tabular-nums">
                            {expediente.displayNumber}
                        </span>
                        <StatusBadge
                            label={ETIQUETA_EXPEDIENTE[expediente.status]}
                            tone={TONO_EXPEDIENTE[expediente.status]}
                        />
                    </span>
                    <span className="mt-2 block truncate text-sm font-medium">
                        {primero?.beneficiaryName ?? 'Sin haberes cargados'}
                    </span>
                    <span className="mt-0.5 block truncate text-xs text-muted-foreground">
                        {expediente.employerName}
                    </span>
                </span>
                <ChevronRight
                    className={cn(
                        'mt-0.5 size-4 shrink-0 text-muted-foreground transition-transform duration-200 motion-reduce:transition-none',
                        abierto && 'rotate-90',
                    )}
                    aria-hidden="true"
                />
            </button>

            <div className="grid grid-cols-2 gap-x-4 gap-y-3 border-t px-4 py-3 text-xs">
                <div>
                    <p className="text-field-label">Reconocido</p>
                    <p className="mt-0.5 text-sm">
                        <Money value={expediente.recognizedTotalAmount} />
                    </p>
                </div>
                <div>
                    <p className="text-field-label">Financiado</p>
                    <p className="mt-0.5 text-sm">
                        <Money
                            value={expediente.fundedTotalAmount}
                            dimWhenZero
                        />
                    </p>
                </div>
                <div className="col-span-2">
                    <p className="text-field-label">Cargado</p>
                    <RecordDates
                        className="mt-0.5"
                        createdAt={expediente.createdAt}
                        lastChange={expediente.lastChange}
                    />
                </div>
                <div className="col-span-2 flex items-center justify-between gap-3">
                    <span className="text-muted-foreground">
                        {cantidad === 1 ? '1 haber' : `${cantidad} haberes`}
                        {expediente.receivedDate &&
                            ` · recibido ${date(expediente.receivedDate)}`}
                    </span>
                    <Button asChild variant="ghost" size="sm" className="h-10">
                        <Link href={show(expediente.id)}>
                            <Eye className="size-4" aria-hidden="true" />
                            Ver detalle
                        </Link>
                    </Button>
                </div>
            </div>

            {abierto && (
                <div id={panelId} className="border-t bg-muted/20">
                    <HaberList haberes={expediente.haberes} />
                </div>
            )}
        </article>
    );
}
