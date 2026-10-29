import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeftRight, EyeOff, Link2, Search } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import PageHeader from '@/components/page-header';
import PaginationFooter from '@/components/pagination-footer';
import type { PaginationData } from '@/components/pagination-footer';
import StatusBadge from '@/components/status-badge';
import type { StatusTone } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cuit as formatCuit, date, money } from '@/lib/format';
import { create as createExtracto } from '@/routes/banco/extractos';
import { ignore, index } from '@/routes/banco/movimientos';

type Movimiento = App.Modules.Banking.Data.BankTransactionListItemData;
type Cuenta = App.Modules.Banking.Data.BankAccountData;

type Paginado<T> = PaginationData & {
    data: T[];
};

type Props = {
    transactions: Paginado<Movimiento>;
    accounts: Cuenta[];
    filters: {
        cuenta: number | null;
        estado: string | null;
        sentido: string | null;
        buscar: string | null;
    };
    canIgnore: boolean;
    canImport: boolean;
    hasAnyTransactions: boolean;
    /** El último saldo que informó un extracto, por cuenta activa. */
    balances: {
        accountId: number;
        label: string;
        balance: string | null;
        date: string | null;
    }[];
    /**
     * Qué se puede hacer con cada crédito pendiente de la página, según el
     * módulo que lleva la caja de su cuenta: identificarlo, o por qué no.
     */
    creditActions: Record<
        number,
        { label: string; href: string | null; note: string | null }
    >;
};

const TONO: Record<string, StatusTone> = {
    pending: 'action',
    partial: 'progress',
    reconciled: 'done',
    ignored: 'neutral',
};

const ETIQUETA: Record<string, string> = {
    pending: 'Sin identificar',
    partial: 'Identificado en parte',
    reconciled: 'Identificado',
    ignored: 'Fuera del circuito',
};

/**
 * Movimientos bancarios canónicos.
 *
 * Es la cola de trabajo que hoy se resuelve a mano en las hojas
 * auxiliares del libro banco: los créditos sin identificar, con el CUIT
 * que el sistema encontró dentro del concepto cuando lo había. Un crédito
 * pendiente de una cuenta con caja se identifica desde acá: el módulo de
 * esa caja dice cómo (`creditActions`).
 */
export default function MovimientosIndex({
    transactions,
    accounts,
    filters,
    canIgnore,
    canImport,
    hasAnyTransactions,
    balances,
    creditActions,
}: Props) {
    const [buscar, setBuscar] = useState(filters.buscar ?? '');
    const [ignorando, setIgnorando] = useState<Movimiento | null>(null);
    const filtersActive = Boolean(
        filters.cuenta || filters.estado || filters.sentido || filters.buscar,
    );

    const filtrar = (cambios: Record<string, string | number | null>) => {
        router.get(
            index().url,
            {
                cuenta: filters.cuenta,
                estado: filters.estado,
                sentido: filters.sentido,
                buscar: filters.buscar,
                ...cambios,
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Movimientos bancarios" />

            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Banco"
                    title="Movimientos bancarios"
                    description={`${transactions.total} movimientos registrados desde los extractos importados.`}
                />

                {balances.length > 0 && (
                    <ul
                        aria-label="Saldo de cada cuenta según el banco"
                        className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                    >
                        {balances.map((b) => (
                            <li
                                key={b.accountId}
                                className="rounded-lg border bg-card px-4 py-3 shadow-raised"
                            >
                                <p className="text-xs tracking-wide text-field-label uppercase">
                                    {b.label}
                                </p>
                                <p className="mt-1 text-lg tabular-nums">
                                    {b.balance === null
                                        ? '—'
                                        : money(b.balance)}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {b.date === null
                                        ? 'Sin saldo informado por un extracto.'
                                        : `Según el banco, al ${date(b.date)}.`}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}

                <div className="flex flex-wrap items-end gap-3">
                    <form
                        className="flex w-full items-end gap-2 sm:w-auto"
                        onSubmit={(e) => {
                            e.preventDefault();
                            filtrar({ buscar: buscar || null });
                        }}
                    >
                        <div className="grid min-w-0 flex-1 gap-1.5 sm:flex-none">
                            <Label htmlFor="buscar" className="text-xs">
                                Buscar
                            </Label>
                            <Input
                                id="buscar"
                                value={buscar}
                                onChange={(e) => setBuscar(e.target.value)}
                                placeholder="Concepto, referencia o CUIT"
                                className="w-full sm:w-64"
                            />
                        </div>
                        <Button
                            type="submit"
                            variant="outline"
                            className="size-11 p-0 sm:size-9"
                            aria-label="Buscar movimientos"
                        >
                            <Search className="size-4" aria-hidden="true" />
                        </Button>
                    </form>

                    <div className="grid gap-1.5">
                        <Label htmlFor="cuenta" className="text-xs">
                            Cuenta
                        </Label>
                        <Select
                            value={filters.cuenta?.toString() ?? 'all'}
                            onValueChange={(value) =>
                                filtrar({
                                    cuenta: value === 'all' ? null : value,
                                })
                            }
                        >
                            <SelectTrigger id="cuenta" className="min-w-40">
                                <SelectValue placeholder="Todas" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Todas</SelectItem>
                                {accounts.map((cuenta) => (
                                    <SelectItem
                                        key={cuenta.id}
                                        value={cuenta.id.toString()}
                                    >
                                        {cuenta.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="estado" className="text-xs">
                            Estado
                        </Label>
                        <Select
                            value={filters.estado ?? 'all'}
                            onValueChange={(value) =>
                                filtrar({
                                    estado: value === 'all' ? null : value,
                                })
                            }
                        >
                            <SelectTrigger id="estado" className="min-w-44">
                                <SelectValue placeholder="Todos" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Todos</SelectItem>
                                <SelectItem value="pending">
                                    Sin identificar
                                </SelectItem>
                                <SelectItem value="partial">
                                    Identificado en parte
                                </SelectItem>
                                <SelectItem value="reconciled">
                                    Identificados
                                </SelectItem>
                                <SelectItem value="ignored">
                                    Fuera del circuito
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="sentido" className="text-xs">
                            Sentido
                        </Label>
                        <Select
                            value={filters.sentido ?? 'all'}
                            onValueChange={(value) =>
                                filtrar({
                                    sentido: value === 'all' ? null : value,
                                })
                            }
                        >
                            <SelectTrigger id="sentido" className="min-w-36">
                                <SelectValue placeholder="Ambos" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Ambos</SelectItem>
                                <SelectItem value="credit">Créditos</SelectItem>
                                <SelectItem value="debit">Débitos</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                {transactions.data.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-10 text-center">
                        <ArrowLeftRight className="mx-auto size-8 text-muted-foreground" />
                        <p className="mt-3 font-medium">
                            {hasAnyTransactions
                                ? 'No hay movimientos que coincidan.'
                                : 'Todavía no hay movimientos bancarios.'}
                        </p>
                        <p className="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                            {hasAnyTransactions
                                ? 'Probá quitar alguno de los filtros aplicados.'
                                : 'Los movimientos aparecen cuando se importa el primer extracto bancario.'}
                        </p>
                        {hasAnyTransactions && filtersActive ? (
                            <Button
                                variant="outline"
                                className="mt-4 min-h-11"
                                onClick={() => {
                                    setBuscar('');
                                    filtrar({
                                        cuenta: null,
                                        estado: null,
                                        sentido: null,
                                        buscar: null,
                                    });
                                }}
                            >
                                Limpiar filtros
                            </Button>
                        ) : canImport ? (
                            <Button asChild className="mt-4 min-h-11">
                                <Link href={createExtracto()}>
                                    Importar primer extracto
                                </Link>
                            </Button>
                        ) : null}
                    </div>
                ) : (
                    // `contain`: sin él, el ancho de la tabla empuja al
                    // contenedor de la página y aparece el scroll horizontal
                    // de toda la pantalla en vez del de la tabla.
                    <div className="overflow-x-auto rounded-lg border [contain:inline-size]">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-2 font-medium text-field-label">
                                        Fecha
                                    </th>
                                    <th className="p-2 font-medium text-field-label">
                                        Concepto
                                    </th>
                                    <th className="p-2 font-medium text-field-label">
                                        Referencia
                                    </th>
                                    <th className="p-2 text-right font-medium text-field-label">
                                        Débito
                                    </th>
                                    <th className="p-2 text-right font-medium text-field-label">
                                        Crédito
                                    </th>
                                    <th className="p-2 text-right font-medium text-field-label">
                                        Saldo
                                    </th>
                                    <th className="p-2 font-medium text-field-label">
                                        Estado
                                    </th>
                                    <th className="p-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {transactions.data.map((m) => (
                                    <tr key={m.id} className="border-t">
                                        <td className="p-2 whitespace-nowrap">
                                            {date(m.transactionDate)}
                                        </td>
                                        <td className="p-2">
                                            <span className="block max-w-[24rem] truncate">
                                                {m.description ?? '—'}
                                            </span>
                                            {m.counterpartyIdentifier && (
                                                <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                                    <Link2 className="size-3" />
                                                    {formatCuit(
                                                        m.counterpartyIdentifier,
                                                    )}
                                                    {m.counterpartyName
                                                        ? ` · ${m.counterpartyName}`
                                                        : ''}
                                                </span>
                                            )}
                                            {m.seenInImports > 1 && (
                                                <span className="ml-2 text-xs text-muted-foreground">
                                                    visto en {m.seenInImports}{' '}
                                                    extractos
                                                </span>
                                            )}
                                        </td>
                                        <td className="p-2 tabular-nums">
                                            {m.operationId ?? '—'}
                                            {m.causalCode && (
                                                <span className="block text-xs text-muted-foreground">
                                                    causal {m.causalCode}
                                                </span>
                                            )}
                                        </td>
                                        <td className="p-2 text-right tabular-nums">
                                            {m.direction === 'debit'
                                                ? money(m.amount)
                                                : ''}
                                        </td>
                                        <td className="p-2 text-right tabular-nums">
                                            {m.direction === 'credit'
                                                ? money(m.amount)
                                                : ''}
                                        </td>
                                        <td className="p-2 text-right text-muted-foreground tabular-nums">
                                            {money(m.balanceAfter)}
                                        </td>
                                        <td className="p-2">
                                            <StatusBadge
                                                label={
                                                    ETIQUETA[
                                                        m.reconciliationStatus
                                                    ] ?? m.reconciliationStatus
                                                }
                                                tone={
                                                    TONO[
                                                        m.reconciliationStatus
                                                    ] ?? 'neutral'
                                                }
                                            />
                                            {m.ignoredReason && (
                                                <p className="mt-1 max-w-[16rem] text-xs text-muted-foreground">
                                                    {m.ignoredReason}
                                                </p>
                                            )}
                                        </td>
                                        <td className="p-2 text-right">
                                            <div className="flex items-center justify-end gap-1">
                                                <AccionDelCredito
                                                    accion={creditActions[m.id]}
                                                />
                                                {canIgnore &&
                                                    m.reconciliationStatus ===
                                                        'pending' && (
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="size-11 sm:size-9"
                                                            aria-label={`Dejar fuera del circuito el movimiento del ${date(m.transactionDate)}${m.description ? `: ${m.description}` : ''}`}
                                                            onClick={() =>
                                                                setIgnorando(m)
                                                            }
                                                        >
                                                            <EyeOff
                                                                className="size-4"
                                                                aria-hidden="true"
                                                            />
                                                        </Button>
                                                    )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {transactions.total > 0 && (
                    <PaginationFooter
                        pagination={transactions}
                        singular="movimiento"
                        plural="movimientos"
                    />
                )}
            </div>

            <DialogoIgnorar
                movimiento={ignorando}
                onClose={() => setIgnorando(null)}
            />
        </>
    );
}

function DialogoIgnorar({
    movimiento,
    onClose,
}: {
    movimiento: Movimiento | null;
    onClose: () => void;
}) {
    const form = useForm({ reason: '' });

    const enviar = (event: React.FormEvent) => {
        event.preventDefault();

        if (!movimiento) {
            return;
        }

        form.patch(ignore(movimiento.id).url, {
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Dialog
            open={movimiento !== null}
            onOpenChange={(abierto) => !abierto && onClose()}
        >
            <DialogContent>
                <form onSubmit={enviar}>
                    <DialogHeader>
                        <DialogTitle>Dejar fuera del circuito</DialogTitle>
                        <DialogDescription>
                            El movimiento sigue existiendo y sigue sumando en el
                            saldo: lo único que cambia es que deja de aparecer
                            entre los pendientes de identificar. Es para las
                            comisiones y las transferencias entre cuentas
                            propias.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2 py-4">
                        <Label htmlFor="reason">Motivo</Label>
                        <Input
                            id="reason"
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                            placeholder="Comisión bancaria del causal 3914"
                        />
                        <InputError message={form.errors.reason} />
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Confirmar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Lo que el módulo de la caja de la cuenta ofrece para un crédito pendiente:
 * identificarlo en su pantalla, o una nota que dice por qué no desde acá.
 */
function AccionDelCredito({
    accion,
}: {
    accion: Props['creditActions'][number] | undefined;
}) {
    if (accion === undefined) {
        return null;
    }

    if (accion.href === null) {
        return (
            <span
                className="max-w-[14rem] text-left text-xs text-muted-foreground"
                title={accion.note ?? undefined}
            >
                {accion.label}
            </span>
        );
    }

    return (
        <Button size="sm" variant="outline" asChild>
            <Link href={accion.href}>{accion.label}</Link>
        </Button>
    );
}
