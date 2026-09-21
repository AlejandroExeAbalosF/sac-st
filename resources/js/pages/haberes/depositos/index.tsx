import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, Clock, Printer, Receipt } from 'lucide-react';
import PageHeader from '@/components/page-header';
import PaginationFooter from '@/components/pagination-footer';
import type { PaginationData } from '@/components/pagination-footer';
import StatusBadge from '@/components/status-badge';
import type { StatusTone } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { date, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { index, planilla, search } from '@/routes/depositos';

type Ticket = App.Modules.Haberes.Data.DepositTicketData;

type Props = {
    tickets: PaginationData & { data: Ticket[] };
    filters: { estado: string };
    counts: { waiting: number; matched: number; discarded: number };
    canRegister: boolean;
    canLink: boolean;
    canDiscard: boolean;
};

const TONO: Record<string, StatusTone> = {
    waiting: 'action',
    matched: 'done',
    discarded: 'neutral',
};

const ETIQUETA: Record<string, string> = {
    waiting: 'Esperando en el banco',
    matched: 'Encontrado',
    discarded: 'Descartado',
};

/**
 * La cola de comprobantes.
 *
 * Es el estado que hoy vive en la cabeza de la contadora: qué depósitos se
 * informaron y todavía no aparecieron en el extracto. Los que esperan van
 * primero y del más viejo al más nuevo, porque un comprobante de hace tres
 * semanas que sigue sin aparecer significa algo —el depósito nunca se
 * hizo, o falta importar un período— y tiene que verse.
 */
export default function DepositosIndex({
    tickets,
    filters,
    counts,
    canRegister,
}: Props) {
    const filtrar = (estado: string) =>
        router.get(
            index().url,
            { estado },
            { preserveState: true, replace: true },
        );

    return (
        <>
            <Head title="Comprobantes de depósito" />

            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Haberes"
                    title="Comprobantes de depósito"
                    description="Lo que los expedientes informaron y todavía hay que encontrar en el banco."
                />

                <div className="flex flex-wrap gap-2">
                    <Filtro
                        activo={filters.estado === 'waiting'}
                        onClick={() => filtrar('waiting')}
                        etiqueta="Esperando"
                        cantidad={counts.waiting}
                    />
                    <Filtro
                        activo={filters.estado === 'matched'}
                        onClick={() => filtrar('matched')}
                        etiqueta="Encontrados"
                        cantidad={counts.matched}
                    />
                    <Filtro
                        activo={filters.estado === 'discarded'}
                        onClick={() => filtrar('discarded')}
                        etiqueta="Descartados"
                        cantidad={counts.discarded}
                    />
                    <Filtro
                        activo={filters.estado === 'todos'}
                        onClick={() => filtrar('todos')}
                        etiqueta="Todos"
                        cantidad={
                            counts.waiting + counts.matched + counts.discarded
                        }
                    />

                    <Button
                        asChild
                        variant="outline"
                        size="sm"
                        className="ml-auto"
                    >
                        {/* La planilla lleva siempre los que esperan, sea cual
                            sea el filtro de la pantalla: un listado de
                            comprobantes ya cruzados no es una cola de trabajo.
                            En pestaña nueva, para no perder el filtro puesto. */}
                        <a
                            href={planilla().url}
                            target="_blank"
                            rel="noreferrer"
                        >
                            <Printer aria-hidden="true" />
                            Planilla
                        </a>
                    </Button>
                </div>

                {tickets.data.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-10 text-center">
                        <Receipt className="mx-auto size-8 text-muted-foreground" />
                        <p className="mt-3 font-medium">
                            {filters.estado === 'waiting'
                                ? 'No hay comprobantes esperando.'
                                : 'No hay comprobantes en este estado.'}
                        </p>
                        {canRegister && filters.estado === 'waiting' && (
                            <p className="mt-1 text-sm text-muted-foreground">
                                Los comprobantes se cargan desde la cuota que
                                paga cada depósito.
                            </p>
                        )}
                    </div>
                ) : (
                    <div className="grid gap-4">
                        <div className="grid gap-3 md:hidden">
                            {tickets.data.map((ticket) => (
                                <TicketCard key={ticket.id} ticket={ticket} />
                            ))}
                        </div>

                        <div className="hidden overflow-x-auto rounded-lg border md:block">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3 font-medium text-field-label">
                                            Expediente
                                        </th>
                                        <th className="p-3 font-medium text-field-label">
                                            Depositado
                                        </th>
                                        <th className="p-3 text-right font-medium text-field-label">
                                            Importe
                                        </th>
                                        <th className="p-3 font-medium text-field-label">
                                            Nº operación
                                        </th>
                                        <th className="p-3 font-medium text-field-label">
                                            Estado
                                        </th>
                                        <th className="p-3" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {tickets.data.map((ticket) => (
                                        <tr
                                            key={ticket.id}
                                            className="border-t transition-colors focus-within:bg-muted/40 hover:bg-muted/40"
                                        >
                                            <td className="p-3">
                                                <Link
                                                    href={search(ticket.id)}
                                                    className="font-medium hover:underline"
                                                >
                                                    {ticket.expedienteNumber}
                                                </Link>
                                                <div className="text-xs text-muted-foreground">
                                                    {ticket.employerName ??
                                                        'Sin empleador'}
                                                    {ticket.installmentLabel
                                                        ? ` · ${ticket.installmentLabel}`
                                                        : ''}
                                                </div>
                                            </td>
                                            <td className="p-3 whitespace-nowrap">
                                                {date(ticket.depositedAt)}
                                                {ticket.status === 'waiting' &&
                                                    ticket.waitingDays > 7 && (
                                                        <span className="mt-0.5 flex items-center gap-1 text-xs text-warning-strong">
                                                            <Clock className="size-3" />
                                                            hace{' '}
                                                            {ticket.waitingDays}{' '}
                                                            días
                                                        </span>
                                                    )}
                                            </td>
                                            <td className="p-3 text-right font-mono tabular-nums">
                                                {money(ticket.amount)}
                                            </td>
                                            <td className="p-3 tabular-nums">
                                                {ticket.operationNumber ?? '—'}
                                            </td>
                                            <td className="p-3">
                                                <StatusBadge
                                                    label={
                                                        ETIQUETA[
                                                            ticket.status
                                                        ] ?? ticket.status
                                                    }
                                                    tone={
                                                        TONO[ticket.status] ??
                                                        'neutral'
                                                    }
                                                />
                                                {ticket.discardedReason && (
                                                    <p className="mt-1 max-w-xs text-xs text-muted-foreground">
                                                        {ticket.discardedReason}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="p-3 text-right">
                                                <Button
                                                    asChild
                                                    variant="ghost"
                                                    size="icon"
                                                >
                                                    <Link
                                                        href={search(ticket.id)}
                                                        aria-label={`Ver comprobante del expediente ${ticket.expedienteNumber}`}
                                                    >
                                                        <ChevronRight aria-hidden="true" />
                                                    </Link>
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <PaginationFooter
                            pagination={tickets}
                            singular="comprobante"
                            plural="comprobantes"
                        />
                    </div>
                )}
            </div>
        </>
    );
}

function TicketCard({ ticket }: { ticket: Ticket }) {
    return (
        <Link
            href={search(ticket.id)}
            className="grid gap-3 rounded-lg border bg-card p-4 shadow-xs transition-colors hover:border-primary/30 hover:bg-accent/20 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            aria-label={`Ver comprobante del expediente ${ticket.expedienteNumber}`}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-sm font-semibold break-words">
                        {ticket.expedienteNumber}
                    </p>
                    <p className="mt-0.5 text-xs break-words text-muted-foreground">
                        {ticket.employerName ?? 'Sin empleador'}
                        {ticket.installmentLabel
                            ? ` · ${ticket.installmentLabel}`
                            : ''}
                    </p>
                </div>
                <StatusBadge
                    label={ETIQUETA[ticket.status] ?? ticket.status}
                    tone={TONO[ticket.status] ?? 'neutral'}
                />
            </div>

            <div className="grid grid-cols-2 gap-3 border-t pt-3">
                <div>
                    <p className="text-xs text-muted-foreground">Depositado</p>
                    <p className="mt-0.5 text-sm">{date(ticket.depositedAt)}</p>
                    {ticket.status === 'waiting' && ticket.waitingDays > 7 && (
                        <p className="mt-1 flex items-center gap-1 text-xs text-warning-strong">
                            <Clock className="size-3" aria-hidden="true" />
                            Hace {ticket.waitingDays} días
                        </p>
                    )}
                </div>
                <div className="text-right">
                    <p className="text-xs text-muted-foreground">Importe</p>
                    <p className="mt-0.5 font-mono text-sm font-semibold tabular-nums">
                        {money(ticket.amount)}
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground tabular-nums">
                        Operación {ticket.operationNumber ?? 'pendiente'}
                    </p>
                </div>
            </div>

            {ticket.discardedReason && (
                <p className="text-xs break-words text-muted-foreground">
                    {ticket.discardedReason}
                </p>
            )}
        </Link>
    );
}

function Filtro({
    activo,
    onClick,
    etiqueta,
    cantidad,
}: {
    activo: boolean;
    onClick: () => void;
    etiqueta: string;
    cantidad: number;
}) {
    return (
        <Button
            variant={activo ? 'default' : 'outline'}
            size="sm"
            onClick={onClick}
        >
            {etiqueta}
            <span
                className={cn(
                    'ml-1 tabular-nums',
                    !activo && 'text-muted-foreground',
                )}
            >
                {cantidad}
            </span>
        </Button>
    );
}
