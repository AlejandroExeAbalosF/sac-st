import { Link } from '@inertiajs/react';
import { ChevronRight, Lock, Maximize2 } from 'lucide-react';
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
import { show as expedienteShow } from '@/routes/expedientes';
import { show as haberShow } from '@/routes/haberes/haber';
import InstallmentList from './installment-list';
import RecordDates from './record-dates';

type Haber = App.Modules.Haberes.Data.HaberRowData;
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

/**
 * Los haberes sin el expediente por delante.
 *
 * El listado de expedientes contesta «qué entró»; este contesta «a quién
 * le debemos», que es la pregunta con la que se atiende por mostrador.
 *
 * Cada fila se despliega para ver sus cuotas, igual que la del expediente
 * despliega sus haberes: es la pregunta que sigue —«¿cuánto de esto ya
 * cobró y por dónde?»— y contestarla sin navegar deja la comparación
 * entre beneficiarios a la vista. Lo que sigue viviendo en la pantalla del
 * haber es *operar* sobre la cuota: el recibo, las imputaciones y el
 * traslado son lo que hay que tener delante para tocarla, y acá no están.
 */
export default function HaberTable({ haberes }: { haberes: Haber[] }) {
    return (
        <table className="w-full border-collapse text-left">
            <caption className="sr-only">
                Haberes reconocidos, con el expediente que los trajo; cada fila
                se despliega para ver sus cuotas
            </caption>
            <thead className="sticky top-0 z-10">
                <tr className="border-b bg-muted text-xs tracking-wide text-field-label uppercase shadow-[0_1px_0_0_var(--border)]">
                    <th scope="col" className="px-3 py-2.5 font-medium">
                        Beneficiario
                    </th>
                    <th scope="col" className="py-2.5 pr-5 font-medium">
                        Expediente
                    </th>
                    <th scope="col" className="py-2.5 pr-5 font-medium">
                        Empleador
                    </th>
                    <th
                        scope="col"
                        className="py-2.5 pr-5 text-right font-medium"
                    >
                        Reconocido
                    </th>
                    <th
                        scope="col"
                        className="py-2.5 pr-5 text-right font-medium"
                    >
                        Financiado
                    </th>
                    <th scope="col" className="py-2.5 pr-5 font-medium">
                        Cuotas
                    </th>
                    <th scope="col" className="py-2.5 pr-5 font-medium">
                        Estado
                    </th>
                    {/*
                     * «Cargado» y no «Fecha»: la fila ya trae la del papel
                     * —«recibido»— y son dos cosas distintas.
                     */}
                    <th scope="col" className="py-2.5 pr-5 font-medium">
                        Cargado
                    </th>
                    <th
                        scope="col"
                        className="py-2.5 pr-4 text-right font-medium"
                    >
                        Acciones
                    </th>
                </tr>
            </thead>
            <tbody>
                {haberes.map((haber) => (
                    <HaberRow key={haber.id} haber={haber} />
                ))}
            </tbody>
        </table>
    );
}

/**
 * Las cuotas del haber, tal como se ven al desplegar su fila.
 *
 * Es la misma lista que dibuja el detalle del expediente, en modo lectura:
 * las etiquetas no se ofrecen y la carga de la próxima cuota tampoco,
 * porque agregarla desde acá dejaría el alta a un clic de una fila que no
 * muestra ni el recibo ni el comprobante contra los que se controla.
 */
function CuotasDelHaber({ haber }: { haber: Haber }) {
    if (haber.installments.length === 0 && haber.installmentCount === 0) {
        return (
            <p className="px-5 py-4 text-sm text-muted-foreground italic">
                Este haber todavía no tiene cuotas cargadas.
            </p>
        );
    }

    return (
        <div className="px-5 py-4">
            <h4 className="text-xs font-semibold tracking-wide text-primary uppercase">
                Cuotas del haber
            </h4>

            <InstallmentList
                expedienteId={haber.expedienteId}
                haberNumber={haber.haberNumber}
                cuotas={haber.installments}
                previstas={haber.installmentCount}
                totalReconocido={haber.assignedAmount}
                etiquetas={[]}
                editable={false}
            />
        </div>
    );
}

/** Tarjetas con la información priorizada para consulta ocasional en móvil. */
export function HaberCardList({ haberes }: { haberes: Haber[] }) {
    return (
        <ul className="grid gap-3">
            {haberes.map((haber) => (
                <li key={haber.id}>
                    <HaberCard haber={haber} />
                </li>
            ))}
        </ul>
    );
}

/** La misma lectura del haber, reordenada para una pantalla angosta. */
function HaberCard({ haber }: { haber: Haber }) {
    const [abierto, setAbierto] = useState(false);
    const panelId = useId();

    return (
        <article className="overflow-hidden rounded-lg border bg-card shadow-xs">
            <div className="p-4">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <p className="truncate text-sm font-semibold">
                            {haber.beneficiaryName}
                        </p>
                        <p className="mt-0.5 font-mono text-xs text-muted-foreground tabular-nums">
                            {haber.beneficiaryDocument || 'Sin documento'}
                        </p>
                    </div>
                    <StatusBadge
                        label={ETIQUETA[haber.status]}
                        tone={TONO[haber.status]}
                    />
                </div>

                {haber.blockReason && (
                    <p className="mt-3 flex items-start gap-1.5 rounded-md bg-destructive-soft px-2.5 py-2 text-xs text-destructive-strong">
                        <Lock
                            className="mt-px size-3 shrink-0"
                            aria-hidden="true"
                        />
                        <span className="min-w-0 break-words">
                            {haber.blockReason}
                        </span>
                    </p>
                )}

                <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 text-xs">
                    <div>
                        <dt className="text-field-label">Expediente</dt>
                        <dd className="mt-0.5 font-mono text-sm tabular-nums">
                            {haber.expedienteNumber}
                        </dd>
                    </div>
                    <div className="min-w-0">
                        <dt className="text-field-label">Empleador</dt>
                        <dd className="mt-0.5 truncate text-sm">
                            {haber.employerName}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-field-label">Reconocido</dt>
                        <dd className="mt-0.5 text-sm">
                            <Money value={haber.assignedAmount} />
                        </dd>
                    </div>
                    <div>
                        <dt className="text-field-label">Financiado</dt>
                        <dd className="mt-0.5 text-sm">
                            <Money value={haber.fundedAmount} dimWhenZero />
                        </dd>
                    </div>
                    <div className="col-span-2">
                        <dt className="text-field-label">Cargado</dt>
                        <dd>
                            <RecordDates
                                className="mt-0.5"
                                createdAt={haber.createdAt}
                                lastChange={haber.lastChange}
                            />
                        </dd>
                    </div>
                </dl>

                {/*
                 * El contador de cuotas es el disparador: es lo que
                 * se está preguntando cuando se lo lee, y en el
                 * teléfono no hay lugar para un control aparte.
                 */}
                <div className="mt-4 flex items-center justify-between gap-3 border-t pt-3 text-xs text-muted-foreground">
                    <button
                        type="button"
                        onClick={() => setAbierto((valor) => !valor)}
                        aria-expanded={abierto}
                        aria-controls={panelId}
                        className="-ml-2 flex min-h-11 min-w-0 items-center gap-1.5 rounded-md px-2 text-left transition-colors outline-none hover:bg-muted/30 focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <ChevronRight
                            className={cn(
                                'size-4 shrink-0 transition-transform duration-200 motion-reduce:transition-none',
                                abierto && 'rotate-90',
                            )}
                            aria-hidden="true"
                        />
                        <span className="min-w-0">
                            {haber.loadedInstallmentCount} de{' '}
                            {haber.installmentCount} cuotas ·{' '}
                            {haber.paidInstallmentCount} pagadas
                        </span>
                        <span className="sr-only">
                            {abierto ? 'Ocultar' : 'Ver'} el detalle de las
                            cuotas
                        </span>
                    </button>
                    <Button
                        asChild
                        variant="outline"
                        size="sm"
                        className="h-10 shrink-0"
                    >
                        <Link
                            href={haberShow([
                                haber.expedienteId,
                                haber.haberNumber,
                            ])}
                        >
                            Ver haber
                        </Link>
                    </Button>
                </div>
            </div>

            {abierto && (
                <div id={panelId} className="border-t bg-muted/20">
                    <CuotasDelHaber haber={haber} />
                </div>
            )}
        </article>
    );
}

function HaberRow({ haber }: { haber: Haber }) {
    const [abierto, setAbierto] = useState(false);
    const panelId = useId();
    const anulado = haber.status === 'cancelled';

    return (
        <>
            <tr
                className={cn(
                    'border-b transition-colors',
                    abierto
                        ? 'bg-accent/50'
                        : 'focus-within:bg-accent/30 hover:bg-accent/30',
                    anulado && !abierto && 'bg-muted/20',
                )}
            >
                {/*
                 * Riel a la izquierda mientras está desplegada: ata visualmente
                 * la fila con el panel de cuotas que aparece debajo, que si no
                 * queda flotando.
                 */}
                <td
                    className={cn(
                        'px-3 py-3',
                        abierto && 'shadow-[inset_3px_0_0_0_var(--primary)]',
                    )}
                >
                    <button
                        type="button"
                        onClick={() => setAbierto((valor) => !valor)}
                        aria-expanded={abierto}
                        aria-controls={panelId}
                        className="-ml-1.5 flex items-center gap-1.5 rounded-md px-1.5 py-1 text-left hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <ChevronRight
                            className={cn(
                                'size-4 shrink-0 text-muted-foreground transition-transform',
                                abierto && 'rotate-90',
                            )}
                            aria-hidden="true"
                        />
                        <span className="text-sm">{haber.beneficiaryName}</span>
                        <span className="sr-only">
                            {abierto ? 'Ocultar' : 'Ver'} las cuotas de este
                            haber
                        </span>
                    </button>
                    <p className="mt-0.5 pl-7 font-mono text-xs text-muted-foreground tabular-nums">
                        {haber.beneficiaryDocument || 'Sin documento'}
                    </p>
                    {/*
                     * El motivo del bloqueo va en la fila y no en un ícono con
                     * globo: es lo que explica por qué ese haber no avanza, y
                     * esconderlo detrás del mouse lo vuelve invisible para
                     * quien recorre la lista con el teclado.
                     */}
                    {haber.blockReason && (
                        <p className="mt-1.5 flex items-start gap-1.5 rounded-md bg-destructive-soft px-2 py-1 text-xs text-destructive-strong">
                            <Lock
                                className="mt-px size-3 shrink-0"
                                aria-hidden="true"
                            />
                            {haber.blockReason}
                        </p>
                    )}
                </td>

                <td className="py-3 pr-5">
                    {/*
                     * El número enlaza al expediente: es el camino de vuelta al
                     * contexto —el resto de los beneficiarios, la carátula, el
                     * total declarado— desde una lista que lo dejó afuera.
                     */}
                    <Link
                        href={expedienteShow(haber.expedienteId)}
                        className="rounded font-mono text-sm tabular-nums underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        {haber.expedienteNumber}
                    </Link>
                    {haber.receivedDate && (
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            recibido {date(haber.receivedDate)}
                        </p>
                    )}
                </td>

                <td className="py-3 pr-5 text-sm">{haber.employerName}</td>

                <td className="py-3 pr-5 text-right text-sm">
                    <Money value={haber.assignedAmount} />
                </td>

                <td className="py-3 pr-5 text-right text-sm">
                    <Money value={haber.fundedAmount} dimWhenZero />
                </td>

                <td className="py-3 pr-5 text-sm whitespace-nowrap">
                    {haber.loadedInstallmentCount} de {haber.installmentCount}
                    <span className="text-muted-foreground">
                        {' '}
                        · {haber.paidInstallmentCount}{' '}
                        {haber.paidInstallmentCount === 1
                            ? 'pagada'
                            : 'pagadas'}
                    </span>
                </td>

                <td className="py-3 pr-5">
                    <StatusBadge
                        label={ETIQUETA[haber.status]}
                        tone={TONO[haber.status]}
                    />
                </td>

                <td className="py-3 pr-5">
                    <RecordDates
                        createdAt={haber.createdAt}
                        lastChange={haber.lastChange}
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
                </td>
            </tr>

            {abierto && (
                <tr id={panelId}>
                    <td
                        colSpan={9}
                        className="border-b bg-accent/25 p-0 shadow-[inset_3px_0_0_0_var(--primary)]"
                    >
                        <CuotasDelHaber haber={haber} />
                    </td>
                </tr>
            )}
        </>
    );
}
