import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, Search, Wallet, X } from 'lucide-react';
import { useState } from 'react';
import PageHeader from '@/components/page-header';
import PaginationFooter from '@/components/pagination-footer';
import type { PaginationData } from '@/components/pagination-footer';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { date, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { index, show } from '@/routes/recepciones';

type Receipt = App.Modules.Haberes.Data.FundReceiptListItemData;

type Props = {
    receipts: PaginationData & { data: Receipt[] };
    filters: { pendientes: boolean; q: string };
    canAllocate: boolean;
};

const MEDIO: Record<string, string> = {
    cash: 'Efectivo',
    cheque: 'Cheque',
    bank: 'Depósito en cuenta',
};

/**
 * Lo que entró, y de eso cuánto todavía no tiene dueño.
 *
 * La columna que importa es «sin asignar»: su total es el saldo de
 * `UNASSIGNED_FUNDS`, que es la cola de trabajo real del área. Por eso el
 * filtro por defecto la muestra primero.
 */
export default function RecepcionesIndex({ receipts, filters }: Props) {
    const [busqueda, setBusqueda] = useState(filters.q);

    /*
     * El filtro y la búsqueda viajan juntos: cambiar uno no puede borrar
     * el otro, o buscar un expediente saldría del «con saldo sin asignar»
     * sin que nadie lo pidiera.
     */
    const consultar = (cambios: { pendientes?: boolean; q?: string }) => {
        const pendientes = cambios.pendientes ?? filters.pendientes;
        const q = (cambios.q ?? busqueda).trim();

        router.get(
            index().url,
            {
                ...(pendientes ? { pendientes: 1 } : {}),
                ...(q === '' ? {} : { q }),
            },
            { preserveState: true, replace: true },
        );
    };

    const filtrar = (pendientes: boolean) => consultar({ pendientes });

    return (
        <>
            <Head title="Recepciones de fondos" />

            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Haberes"
                    title="Recepciones de fondos"
                    description="El dinero que entró. Mientras no se asigne a una cuota, sigue sin tener dueño."
                />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        consultar({});
                    }}
                    className="flex flex-wrap items-center gap-2"
                    role="search"
                >
                    <div className="relative min-w-0 flex-1 sm:max-w-md">
                        <Search
                            className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            value={busqueda}
                            onChange={(e) => setBusqueda(e.target.value)}
                            className="pl-8"
                            placeholder="Expediente, depositante o importe"
                            aria-label="Buscar recepciones"
                        />
                        {busqueda !== '' && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="absolute top-1/2 right-1 size-7 -translate-y-1/2"
                                aria-label="Limpiar la búsqueda"
                                onClick={() => {
                                    setBusqueda('');
                                    consultar({ q: '' });
                                }}
                            >
                                <X className="size-3.5" aria-hidden="true" />
                            </Button>
                        )}
                    </div>
                    <Button type="submit" variant="outline">
                        Buscar
                    </Button>
                </form>

                <div className="flex flex-wrap gap-2">
                    <Filtro
                        activo={filters.pendientes}
                        onClick={() => filtrar(true)}
                        etiqueta="Con saldo sin asignar"
                    />
                    <Filtro
                        activo={!filters.pendientes}
                        onClick={() => filtrar(false)}
                        etiqueta="Todas"
                    />
                </div>

                {receipts.data.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-10 text-center">
                        <Wallet className="mx-auto size-8 text-muted-foreground" />
                        <p className="mt-3 font-medium">
                            {filters.q !== ''
                                ? `Ninguna recepción coincide con «${filters.q}».`
                                : filters.pendientes
                                  ? 'No queda dinero sin asignar.'
                                  : 'Todavía no hay recepciones registradas.'}
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {filters.q !== ''
                                ? 'Se busca por número de expediente, nombre del depositante o importe.'
                                : 'Una recepción nace al cruzar el comprobante del expediente con el movimiento del banco.'}
                        </p>
                    </div>
                ) : (
                    <div className="grid gap-4">
                        <div className="grid gap-3 md:hidden">
                            {receipts.data.map((receipt) => (
                                <ReceiptCard
                                    key={receipt.id}
                                    receipt={receipt}
                                />
                            ))}
                        </div>

                        <div className="hidden overflow-x-auto rounded-lg border md:block">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3 font-medium text-field-label">
                                            Recibido
                                        </th>
                                        <th className="p-3 font-medium text-field-label">
                                            Expediente
                                        </th>
                                        <th className="p-3 font-medium text-field-label">
                                            Medio
                                        </th>
                                        <th className="p-3 text-right font-medium text-field-label">
                                            Importe
                                        </th>
                                        <th className="p-3 text-right font-medium text-field-label">
                                            Sin asignar
                                        </th>
                                        <th className="p-3" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {receipts.data.map((receipt) => {
                                        const revertida =
                                            receipt.reversedAt !== null;
                                        const pendiente =
                                            !revertida &&
                                            Number(receipt.unallocated) > 0;

                                        return (
                                            <tr
                                                key={receipt.id}
                                                className="border-t transition-colors focus-within:bg-muted/40 hover:bg-muted/40"
                                            >
                                                <td className="p-3 whitespace-nowrap">
                                                    {date(receipt.receivedDate)}
                                                </td>
                                                <td className="p-3">
                                                    {receipt.expedienteNumber ? (
                                                        <Link
                                                            href={show(
                                                                receipt.id,
                                                            )}
                                                            className="font-medium hover:underline"
                                                        >
                                                            {
                                                                receipt.expedienteNumber
                                                            }
                                                        </Link>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            Sin comprobante
                                                        </span>
                                                    )}
                                                    {receipt.depositorName && (
                                                        <div className="text-xs text-muted-foreground">
                                                            {
                                                                receipt.depositorName
                                                            }
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="p-3">
                                                    {MEDIO[receipt.medium] ??
                                                        receipt.medium}
                                                </td>
                                                <td className="p-3 text-right font-mono tabular-nums">
                                                    {money(receipt.amount)}
                                                </td>
                                                <td
                                                    className={cn(
                                                        'p-3 text-right font-mono tabular-nums',
                                                        pendiente
                                                            ? 'font-semibold text-warning-strong'
                                                            : 'text-muted-foreground',
                                                    )}
                                                >
                                                    <span
                                                        className={cn(
                                                            revertida &&
                                                                'line-through',
                                                        )}
                                                    >
                                                        {money(
                                                            receipt.unallocated,
                                                        )}
                                                    </span>
                                                    {!pendiente && (
                                                        <div className="mt-1">
                                                            <StatusBadge
                                                                label={
                                                                    revertida
                                                                        ? 'Revertida'
                                                                        : 'Asignada'
                                                                }
                                                                tone={
                                                                    revertida
                                                                        ? 'neutral'
                                                                        : 'done'
                                                                }
                                                            />
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="p-3 text-right">
                                                    <Button
                                                        asChild
                                                        variant="ghost"
                                                        size="icon"
                                                    >
                                                        <Link
                                                            href={show(
                                                                receipt.id,
                                                            )}
                                                            aria-label={`Ver recepción ${receipt.expedienteNumber ?? receipt.id}`}
                                                        >
                                                            <ChevronRight aria-hidden="true" />
                                                        </Link>
                                                    </Button>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>

                        <PaginationFooter
                            pagination={receipts}
                            singular="recepción"
                            plural="recepciones"
                        />
                    </div>
                )}
            </div>
        </>
    );
}

function ReceiptCard({ receipt }: { receipt: Receipt }) {
    const pendiente = Number(receipt.unallocated) > 0;

    return (
        <Link
            href={show(receipt.id)}
            className="grid gap-3 rounded-lg border bg-card p-4 shadow-xs transition-colors hover:border-primary/30 hover:bg-accent/20 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            aria-label={`Ver recepción ${receipt.expedienteNumber ?? receipt.id}`}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-xs text-muted-foreground">
                        {date(receipt.receivedDate)} ·{' '}
                        {MEDIO[receipt.medium] ?? receipt.medium}
                    </p>
                    <p className="mt-1 text-sm font-semibold break-words">
                        {receipt.expedienteNumber ?? 'Sin comprobante'}
                    </p>
                    {receipt.depositorName && (
                        <p className="mt-0.5 text-xs break-words text-muted-foreground">
                            {receipt.depositorName}
                        </p>
                    )}
                </div>
                <ChevronRight
                    className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
            </div>

            <div className="grid grid-cols-2 gap-3 border-t pt-3">
                <div>
                    <p className="text-xs text-muted-foreground">Importe</p>
                    <p className="mt-0.5 font-mono text-sm font-semibold tabular-nums">
                        {money(receipt.amount)}
                    </p>
                </div>
                <div className="text-right">
                    <p className="text-xs text-muted-foreground">Sin asignar</p>
                    <p
                        className={cn(
                            'mt-0.5 font-mono text-sm tabular-nums',
                            pendiente
                                ? 'font-semibold text-warning-strong'
                                : 'text-muted-foreground',
                        )}
                    >
                        {money(receipt.unallocated)}
                    </p>
                    {!pendiente && <StatusBadge label="Asignada" tone="done" />}
                </div>
            </div>
        </Link>
    );
}

function Filtro({
    activo,
    onClick,
    etiqueta,
}: {
    activo: boolean;
    onClick: () => void;
    etiqueta: string;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'rounded-full border px-3 py-1.5 text-sm transition-colors',
                activo
                    ? 'border-transparent bg-primary text-primary-foreground'
                    : 'hover:bg-muted',
            )}
        >
            {etiqueta}
        </button>
    );
}
