import { Head, router } from '@inertiajs/react';
import { FileSpreadsheet, Info } from 'lucide-react';
import Money from '@/components/money';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { date } from '@/lib/format';
import { exportar, index as movimientos } from '@/routes/multas/movimientos';

type Fila = {
    date: string;
    receipt: string;
    caseLabel: string;
    caption: string;
    detail: string;
    amount: string;
    /** Si suma al total de la sección: un depósito cancelado queda como antecedente. */
    counted: boolean;
};

type Seccion = {
    key: string;
    title: string;
    dateLabel: string;
    count: number;
    total: string;
    /** Lo que quedó fuera del total: hoy, los depósitos cancelados. */
    excludedCount: number;
    excludedTotal: string;
    rows: Fila[];
};

/**
 * La consulta operativa de un mes de Multas.
 *
 * **No es la rendición oficial**, que espera el modelo del área (P02). Cada
 * sección dice qué fecha filtra y se suma sola: un cobro, su aplicación, su
 * depósito y el reemplazo de su cheque son hechos distintos del mismo
 * dinero, y sumarlos entre sí lo contaría varias veces.
 */
export default function MovimientosDeMultas({
    month,
    currentMonth,
    sections,
    shown,
}: {
    month: string;
    currentMonth: string;
    sections: Seccion[];
    shown: number;
}) {
    return (
        <>
            <Head title="Movimientos de Multas" />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Multas"
                    title="Movimientos del mes"
                    description="Cobros, aplicaciones, depósitos, rechazos, reemplazos y créditos identificados, cada uno por su fecha."
                    actions={
                        <>
                            <Input
                                type="month"
                                value={month}
                                max={currentMonth}
                                className="w-44"
                                aria-label="Mes"
                                onChange={(e) =>
                                    e.target.value &&
                                    router.get(
                                        movimientos({
                                            query: { mes: e.target.value },
                                        }).url,
                                    )
                                }
                            />
                            <Button variant="outline" asChild>
                                <a
                                    href={
                                        exportar({ query: { mes: month } }).url
                                    }
                                >
                                    <FileSpreadsheet className="size-4" />
                                    Exportar
                                </a>
                            </Button>
                        </>
                    }
                />

                <p className="flex items-start gap-2 rounded-md border border-warning-soft bg-warning-soft px-3 py-2 text-sm text-warning-strong">
                    <Info
                        className="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    Consulta operativa: no es la rendición oficial. Las
                    secciones no se suman entre sí.
                </p>

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
                            <div className="flex flex-col items-end gap-0.5 text-sm">
                                <div className="flex items-center gap-3">
                                    <StatusBadge
                                        label={String(seccion.count)}
                                        tone="neutral"
                                    />
                                    <Money value={seccion.total} />
                                </div>
                                {seccion.excludedCount > 0 && (
                                    <p className="text-xs text-muted-foreground">
                                        {seccion.excludedCount === 1
                                            ? '1 cancelado'
                                            : `${seccion.excludedCount} cancelados`}{' '}
                                        por{' '}
                                        <Money value={seccion.excludedTotal} />{' '}
                                        fuera del total
                                    </p>
                                )}
                            </div>
                        </header>

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
                                                Multa
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
                                            <tr
                                                key={i}
                                                className={
                                                    fila.counted
                                                        ? undefined
                                                        : 'text-muted-foreground'
                                                }
                                            >
                                                <td className="px-4 py-1.5 whitespace-nowrap">
                                                    {date(fila.date)}
                                                </td>
                                                <td className="px-4 py-1.5 font-mono text-xs">
                                                    {fila.receipt || '—'}
                                                </td>
                                                <td className="px-4 py-1.5">
                                                    {fila.caseLabel ? (
                                                        <>
                                                            <span className="font-mono text-xs">
                                                                {fila.caseLabel}
                                                            </span>{' '}
                                                            {fila.caption}
                                                        </>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            Sin multa
                                                        </span>
                                                    )}
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
