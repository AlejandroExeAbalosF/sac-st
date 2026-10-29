import { Head, router } from '@inertiajs/react';
import { FileText, Download } from 'lucide-react';
import { useState } from 'react';
import Money from '@/components/money';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import DocumentFrame, {
    HOJA_ALTO_A4,
} from '@/features/haberes/components/document-frame';
import { dateTime } from '@/lib/format';
import { pdf, store, view } from '@/routes/multas/rendiciones';

type Informe = {
    id: number;
    kind: 'on_time' | 'late';
    version: number;
    current: boolean;
    total: string;
    rows: number;
    emittedAt: string;
    emittedBy: string | null;
};

type Mes = {
    closingId: number;
    month: string;
    status: 'closed' | 'reopened';
    version: number;
    reports: Informe[];
};

const MESES = [
    'enero',
    'febrero',
    'marzo',
    'abril',
    'mayo',
    'junio',
    'julio',
    'agosto',
    'septiembre',
    'octubre',
    'noviembre',
    'diciembre',
];

const TITULO: Record<Informe['kind'], string> = {
    on_time: 'En término',
    late: 'Fuera de término',
};

function nombreDelMes(mes: string): string {
    const [anio, numero] = mes.split('-').map(Number);
    const nombre = MESES[numero - 1];

    return `${nombre.charAt(0).toUpperCase()}${nombre.slice(1)} de ${anio}`;
}

/**
 * La rendición mensual de Multas a la SAF (P02).
 *
 * Por cada mes cerrado, sus dos notas: los ingresos acreditados y
 * reconocidos en el mes (en término), y los reconocidos en el mes con una
 * acreditación de un mes anterior (fuera de término). El corte es el fin del
 * mes, no su cierre. Se emiten una vez por cierre y quedan guardadas; si el
 * mes se reabre y se vuelve a cerrar, sale una versión nueva y la anterior
 * se conserva.
 */
export default function Rendiciones({
    months,
    can,
}: {
    months: Mes[];
    can: { emit: boolean };
}) {
    const [viendo, setViendo] = useState<Informe | null>(null);
    const [emitiendo, setEmitiendo] = useState<number | null>(null);

    const emitir = (mes: Mes) => {
        setEmitiendo(mes.closingId);
        router.post(
            store(mes.closingId).url,
            {},
            {
                preserveScroll: true,
                onFinish: () => setEmitiendo(null),
            },
        );
    };

    return (
        <>
            <Head title="Rendiciones de Multas" />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Multas"
                    title="Rendiciones mensuales"
                    description="Las dos notas de cada mes a la SAF: los ingresos en término y los fuera de término. Se emiten con el mes cerrado."
                />

                {months.length === 0 && (
                    <p className="rounded-lg border bg-card px-5 py-6 text-center text-sm text-muted-foreground">
                        Todavía no hay meses cerrados en la caja de Multas.
                    </p>
                )}

                {months.map((mes) => {
                    const vigentes = mes.reports.filter((r) => r.current);
                    const anteriores = mes.reports.filter((r) => !r.current);
                    const cerrado = mes.status === 'closed';

                    return (
                        <section
                            key={mes.closingId}
                            className="rounded-lg border bg-card shadow-raised"
                        >
                            <header className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3">
                                <div className="flex items-center gap-2">
                                    <h2 className="text-sm font-semibold">
                                        {nombreDelMes(mes.month)}
                                    </h2>
                                    <StatusBadge
                                        label={
                                            cerrado ? 'Cerrado' : 'Reabierto'
                                        }
                                        tone={cerrado ? 'done' : 'blocked'}
                                    />
                                </div>
                                {cerrado &&
                                    vigentes.length === 0 &&
                                    can.emit && (
                                        <Button
                                            size="sm"
                                            disabled={
                                                emitiendo === mes.closingId
                                            }
                                            onClick={() => emitir(mes)}
                                        >
                                            {emitiendo === mes.closingId
                                                ? 'Generando…'
                                                : 'Generar rendición'}
                                        </Button>
                                    )}
                            </header>

                            {vigentes.length === 0 ? (
                                <p className="px-5 py-4 text-sm text-muted-foreground">
                                    {cerrado
                                        ? 'Sin rendición emitida para este cierre.'
                                        : 'El mes está reabierto: la rendición se emite al volver a cerrarlo.'}
                                </p>
                            ) : (
                                <ul className="divide-y">
                                    {vigentes.map((informe) => (
                                        <FilaDeInforme
                                            key={informe.id}
                                            informe={informe}
                                            onVer={() => setViendo(informe)}
                                        />
                                    ))}
                                </ul>
                            )}

                            {anteriores.length > 0 && (
                                <details className="border-t px-5 py-3 text-sm">
                                    <summary className="cursor-pointer text-xs text-muted-foreground">
                                        Versiones anteriores (
                                        {anteriores.length})
                                    </summary>
                                    <ul className="mt-2 divide-y">
                                        {anteriores.map((informe) => (
                                            <FilaDeInforme
                                                key={informe.id}
                                                informe={informe}
                                                onVer={() => setViendo(informe)}
                                            />
                                        ))}
                                    </ul>
                                </details>
                            )}
                        </section>
                    );
                })}
            </div>

            {viendo && (
                <Dialog
                    open
                    onOpenChange={(abierto) => !abierto && setViendo(null)}
                >
                    <DialogContent className="sm:max-w-3xl">
                        <DialogHeader>
                            <DialogTitle>
                                {TITULO[viendo.kind]} · versión {viendo.version}
                            </DialogTitle>
                        </DialogHeader>
                        <DocumentFrame
                            url={view(viendo.id).url}
                            titulo={`Rendición de Multas, ${TITULO[viendo.kind]}`}
                            hojaAlto={HOJA_ALTO_A4}
                            className="w-full"
                            maxAlto="65vh"
                        />
                    </DialogContent>
                </Dialog>
            )}
        </>
    );
}

function FilaDeInforme({
    informe,
    onVer,
}: {
    informe: Informe;
    onVer: () => void;
}) {
    return (
        <li className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3 text-sm">
            <div className="min-w-0 flex-1">
                <p className="font-medium">
                    {TITULO[informe.kind]} · <Money value={informe.total} />
                </p>
                <p className="text-xs text-muted-foreground">
                    {informe.rows} {informe.rows === 1 ? 'ingreso' : 'ingresos'}{' '}
                    · versión {informe.version} · emitida el{' '}
                    {dateTime(informe.emittedAt)}
                    {informe.emittedBy && ` por ${informe.emittedBy}`}
                </p>
            </div>
            <span className="flex gap-2">
                <Button size="sm" variant="outline" onClick={onVer}>
                    <FileText className="size-4" />
                    Ver
                </Button>
                <Button size="sm" variant="outline" asChild>
                    <a href={pdf(informe.id).url}>
                        <Download className="size-4" />
                        PDF
                    </a>
                </Button>
            </span>
        </li>
    );
}
