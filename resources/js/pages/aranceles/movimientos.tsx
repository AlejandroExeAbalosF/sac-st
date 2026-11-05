import { Head, router } from '@inertiajs/react';
import { FileSpreadsheet, Info } from 'lucide-react';
import Money from '@/components/money';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { date, sumAmounts } from '@/lib/format';
import { exportar, index as movimientos } from '@/routes/aranceles/movimientos';

type Fila = {
    date: string;
    receipt: string;
    payer: string;
    concept: string;
    detail: string;
    amount: string;
};

type Seccion = {
    key: string;
    title: string;
    dateLabel: string;
    filtered: boolean;
    count: number;
    total: string;
    rows: Fila[];
};

type PorTipo = {
    code: number;
    name: string;
    quantity: number;
    cash: string;
    bank: string;
    total: string;
};

type Props = {
    month: string;
    currentMonth: string;
    filters: { medio: string | null; tipo: number | null };
    feeTypes: { id: number; code: number; name: string }[];
    byType: PorTipo[];
    byReason: { reason: string; count: number; total: string }[];
    sections: Seccion[];
    shown: number;
};

const TODOS = 'todos';

/**
 * El informe de un mes de Aranceles (reglas A100 a A105).
 *
 * **No es una rendición formal:** el área todavía no dio un modelo. Cada sección
 * dice qué fecha filtra y se suma sola: un cobro y su traspaso son hechos distintos
 * del mismo dinero, y sumarlos lo contaría dos veces.
 */
export default function MovimientosDeAranceles({
    month,
    currentMonth,
    filters,
    feeTypes,
    byType,
    byReason,
    sections,
    shown,
}: Props) {
    const consulta = {
        mes: month,
        ...(filters.medio ? { medio: filters.medio } : {}),
        ...(filters.tipo ? { tipo: filters.tipo } : {}),
    };

    const filtrar = (cambios: Record<string, string | number | null>) => {
        const siguiente: Record<string, string | number | null> = {
            ...consulta,
            ...cambios,
        };

        router.get(
            movimientos({
                query: Object.fromEntries(
                    Object.entries(siguiente).filter(([, v]) => v !== null),
                ) as Record<string, string | number>,
            }).url,
            {},
            { preserveScroll: true },
        );
    };

    const hayFiltros = filters.medio !== null || filters.tipo !== null;

    return (
        <>
            <Head title="Movimientos de Aranceles" />

            {/* Sin aportar ancho propio: las tablas anchas se desplazan en su recuadro y no empujan la página. */}
            <div className="flex flex-col gap-6 p-4 [contain:inline-size] sm:p-6">
                <PageHeader
                    eyebrow="Aranceles"
                    title="Movimientos del mes"
                    description="Lo recaudado por tipo de arancel, los ingresos parciales, el movimiento de fondos y los importes distintos de lo calculado."
                    actions={
                        <Button variant="outline" asChild>
                            <a href={exportar({ query: consulta }).url}>
                                <FileSpreadsheet
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Exportar
                            </a>
                        </Button>
                    }
                />

                <p className="flex items-start gap-2 rounded-md border border-warning-soft bg-warning-soft px-3 py-2 text-sm text-warning-strong">
                    <Info
                        className="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    Consulta operativa: no es una rendición formal. Las
                    secciones no se suman entre sí.
                </p>

                <div className="flex flex-wrap items-end gap-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="mov-mes">Mes</Label>
                        <Input
                            id="mov-mes"
                            type="month"
                            value={month}
                            max={currentMonth}
                            className="w-44"
                            onChange={(e) =>
                                e.target.value &&
                                filtrar({ mes: e.target.value })
                            }
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="mov-medio">Medio</Label>
                        <Select
                            value={filters.medio ?? TODOS}
                            onValueChange={(v) =>
                                filtrar({ medio: v === TODOS ? null : v })
                            }
                        >
                            <SelectTrigger id="mov-medio" className="w-48">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={TODOS}>Todos</SelectItem>
                                <SelectItem value="cash">Efectivo</SelectItem>
                                <SelectItem value="bank">
                                    Depósito o transferencia
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid min-w-0 gap-1.5">
                        <Label htmlFor="mov-tipo">Tipo de arancel</Label>
                        <Select
                            value={filters.tipo ? String(filters.tipo) : TODOS}
                            onValueChange={(v) =>
                                filtrar({
                                    tipo: v === TODOS ? null : Number(v),
                                })
                            }
                        >
                            <SelectTrigger
                                id="mov-tipo"
                                className="w-72 max-w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={TODOS}>Todos</SelectItem>
                                {feeTypes.map((t) => (
                                    <SelectItem key={t.id} value={String(t.id)}>
                                        {t.code} · {t.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                {hayFiltros && (
                    <p className="text-sm text-muted-foreground">
                        Los filtros aplican a la recaudación y a las
                        diferencias. Las demás secciones muestran el mes entero.
                    </p>
                )}

                <section className="rounded-lg border bg-card shadow-raised">
                    <header className="border-b px-5 py-3">
                        <h2 className="text-sm font-semibold">
                            Recaudación por tipo de arancel
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            Por el mes del recibo, con las anulaciones y
                            correcciones restando en el suyo
                        </p>
                    </header>
                    {byType.length === 0 ? (
                        <p className="px-5 py-4 text-sm text-muted-foreground">
                            Nada en el mes.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-xs tracking-wide text-muted-foreground uppercase">
                                    <tr>
                                        <th className="px-4 py-2 text-left font-medium">
                                            Arancel
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Cantidad
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Efectivo
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Banco
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Total
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {byType.map((t) => (
                                        <tr key={t.code}>
                                            <td className="px-4 py-1.5">
                                                {t.code} · {t.name}
                                            </td>
                                            <td className="px-4 py-1.5 text-right tabular-nums">
                                                {t.quantity}
                                            </td>
                                            <td className="px-4 py-1.5 text-right">
                                                <Money value={t.cash} />
                                            </td>
                                            <td className="px-4 py-1.5 text-right">
                                                <Money value={t.bank} />
                                            </td>
                                            <td className="px-4 py-1.5 text-right font-medium">
                                                <Money value={t.total} />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot className="border-t font-semibold">
                                    <tr>
                                        <td className="px-4 py-2" colSpan={2}>
                                            Total
                                        </td>
                                        <td className="px-4 py-2 text-right">
                                            <Money
                                                value={sumAmounts(
                                                    byType.map((t) => t.cash),
                                                )}
                                            />
                                        </td>
                                        <td className="px-4 py-2 text-right">
                                            <Money
                                                value={sumAmounts(
                                                    byType.map((t) => t.bank),
                                                )}
                                            />
                                        </td>
                                        <td className="px-4 py-2 text-right">
                                            <Money
                                                value={sumAmounts(
                                                    byType.map((t) => t.total),
                                                )}
                                            />
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    )}
                </section>

                {sections.map((seccion) => (
                    <section
                        key={seccion.key}
                        className="rounded-lg border bg-card shadow-raised"
                    >
                        <header className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b px-5 py-3">
                            <div>
                                <h2 className="text-sm font-semibold">
                                    {seccion.title}
                                </h2>
                                <p className="text-xs text-muted-foreground">
                                    Por {seccion.dateLabel.toLowerCase()}
                                </p>
                            </div>
                            <div className="flex items-center gap-3 text-sm">
                                <StatusBadge
                                    label={String(seccion.count)}
                                    tone="neutral"
                                />
                                <Money value={seccion.total} />
                            </div>
                        </header>

                        {seccion.key === 'diferencias' &&
                            byReason.length > 0 && (
                                <ul className="flex flex-wrap gap-x-6 gap-y-1 border-b px-5 py-3 text-sm">
                                    {byReason.map((m) => (
                                        <li key={m.reason}>
                                            {m.reason} ({m.count}):{' '}
                                            <Money value={m.total} />
                                        </li>
                                    ))}
                                </ul>
                            )}

                        {seccion.rows.length === 0 ? (
                            <p className="px-5 py-4 text-sm text-muted-foreground">
                                Nada en el mes.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50 text-xs tracking-wide text-muted-foreground uppercase">
                                        <tr>
                                            <th className="px-4 py-2 text-left font-medium">
                                                Fecha
                                            </th>
                                            <th className="px-4 py-2 text-left font-medium">
                                                Recibo
                                            </th>
                                            <th className="px-4 py-2 text-left font-medium">
                                                Quién paga
                                            </th>
                                            <th className="px-4 py-2 text-left font-medium">
                                                Concepto
                                            </th>
                                            <th className="px-4 py-2 text-left font-medium">
                                                Detalle
                                            </th>
                                            <th className="px-4 py-2 text-right font-medium">
                                                Importe
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {seccion.rows.map((fila, i) => (
                                            <tr key={i}>
                                                <td className="px-4 py-1.5 whitespace-nowrap">
                                                    {date(fila.date)}
                                                </td>
                                                <td className="px-4 py-1.5 font-mono text-xs">
                                                    {fila.receipt || '—'}
                                                </td>
                                                <td className="px-4 py-1.5">
                                                    {fila.payer || '—'}
                                                </td>
                                                <td className="px-4 py-1.5">
                                                    {fila.concept || '—'}
                                                </td>
                                                <td className="px-4 py-1.5 text-muted-foreground">
                                                    {fila.detail}
                                                </td>
                                                <td className="px-4 py-1.5 text-right">
                                                    <Money
                                                        value={fila.amount}
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {seccion.count > seccion.rows.length && (
                            <p className="border-t px-5 py-2 text-xs text-muted-foreground">
                                Se muestran {shown} de {seccion.count}: el Excel
                                las trae todas.
                            </p>
                        )}
                    </section>
                ))}
            </div>
        </>
    );
}
