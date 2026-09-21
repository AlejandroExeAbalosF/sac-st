import { Head, Link, router } from '@inertiajs/react';
import { Banknote, ChevronRight, Clock, Printer } from 'lucide-react';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import type { StatusTone } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { date, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { show as haberShow } from '@/routes/haberes/haber';
import { index, print } from '@/routes/planillas';

type CounterRow = App.Modules.Haberes.Data.CounterPayoutRowData;
type TransferRow = App.Modules.Haberes.Data.UnconfirmedTransferRowData;

type Cola = 'mostrador' | 'transferencias';

type Props = {
    cola: Cola;
    counter: CounterRow[];
    transfers: TransferRow[];
    /** Sumados en el servidor: acá el dinero no se suma, se muestra. */
    counterTotal: string;
    transfersTotal: string;
};

const TONO: Record<string, StatusTone> = {
    report_received: 'progress',
    bank_debit_observed: 'progress',
    ready_for_validation: 'action',
};

const ETIQUETA: Record<string, string> = {
    report_received: 'Transferencia informada',
    bank_debit_observed: 'Débito observado',
    ready_for_validation: 'Listo para validar',
};

/**
 * Las dos colas del egreso.
 *
 * Una pantalla y no dos porque son las dos mitades del mismo dinero
 * saliendo: lo que se entrega en mano y lo que salió por el banco y todavía
 * nadie dio por hecho. La primera es del cajero y la segunda del contador,
 * pero las dos contestan la misma pregunta —a quién le debemos y en qué
 * estado está—.
 *
 * No se opera desde acá: cada fila lleva al haber, que es donde viven los
 * actos. Esto lista, cuenta e imprime.
 */
export default function Egresos({
    cola,
    counter,
    transfers,
    counterTotal,
    transfersTotal,
}: Props) {
    const cambiar = (siguiente: Cola) =>
        router.get(
            index().url,
            { cola: siguiente },
            { preserveState: true, replace: true },
        );

    const enMostrador = cola === 'mostrador';

    return (
        <>
            <Head title="Planillas" />

            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Haberes"
                    title="Planillas"
                    description="Lo que está listo para entregarse y lo que salió por el banco sin confirmar."
                />

                <div className="flex flex-wrap items-center gap-2">
                    <Solapa
                        activo={enMostrador}
                        onClick={() => cambiar('mostrador')}
                        etiqueta="Por entregar"
                        cantidad={counter.length}
                    />
                    <Solapa
                        activo={!enMostrador}
                        onClick={() => cambiar('transferencias')}
                        etiqueta="Transferencias sin confirmar"
                        cantidad={transfers.length}
                    />

                    <Button
                        asChild
                        variant="outline"
                        size="sm"
                        className="ml-auto"
                    >
                        {/* La planilla de lo que se está mirando. En pestaña
                            nueva: la lista sigue acá cuando el papel ya salió
                            por la impresora. */}
                        <a
                            href={print({ query: { cola } }).url}
                            target="_blank"
                            rel="noreferrer"
                        >
                            <Printer aria-hidden="true" />
                            Planilla
                        </a>
                    </Button>
                </div>

                {enMostrador ? (
                    <Mostrador filas={counter} total={counterTotal} />
                ) : (
                    <Transferencias filas={transfers} total={transfersTotal} />
                )}
            </div>
        </>
    );
}

function Mostrador({ filas, total }: { filas: CounterRow[]; total: string }) {
    if (filas.length === 0) {
        return (
            <Vacio
                titulo="No hay cuotas en efectivo listas para entregar."
                detalle="Una cuota aparece acá cuando está financiada, tiene su recibo de ingreso y no la bloquea ninguna etiqueta."
            />
        );
    }

    return (
        <div className="grid gap-4">
            <div className="grid gap-3 md:hidden">
                {filas.map((fila) => (
                    <Link
                        key={fila.installmentId}
                        href={haberShow([fila.expedienteId, fila.haberNumber])}
                        className="rounded-lg border p-3 transition-colors hover:bg-muted/40"
                    >
                        <div className="flex items-baseline justify-between gap-3">
                            <span className="font-medium">
                                {fila.beneficiaryName}
                            </span>
                            <span className="font-mono tabular-nums">
                                {money(fila.amount)}
                            </span>
                        </div>
                        <div className="mt-1 text-xs text-muted-foreground">
                            {fila.expedienteNumber} · {fila.installmentLabel}
                        </div>
                    </Link>
                ))}
            </div>

            <div className="hidden overflow-x-auto rounded-lg border md:block">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="p-3 font-medium text-field-label">
                                Beneficiario
                            </th>
                            <th className="p-3 font-medium text-field-label">
                                Expediente
                            </th>
                            <th className="p-3 font-medium text-field-label">
                                Haber / Cuota
                            </th>
                            <th className="p-3 text-right font-medium text-field-label">
                                Importe
                            </th>
                            <th className="p-3 font-medium text-field-label">
                                Recibo ingreso
                            </th>
                            <th className="p-3" />
                        </tr>
                    </thead>
                    <tbody>
                        {filas.map((fila) => (
                            <tr
                                key={fila.installmentId}
                                className="border-t transition-colors focus-within:bg-muted/40 hover:bg-muted/40"
                            >
                                <td className="p-3">
                                    <div className="font-medium">
                                        {fila.beneficiaryName}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {fila.beneficiaryDocument ??
                                            'Sin documento'}
                                    </div>
                                </td>
                                <td className="p-3">
                                    {fila.expedienteNumber}
                                    <div className="text-xs text-muted-foreground">
                                        {fila.employerName ?? 'Sin empleador'}
                                    </div>
                                </td>
                                <td className="p-3">
                                    {fila.installmentLabel}
                                    {fila.concept && (
                                        <div className="max-w-xs text-xs text-muted-foreground">
                                            {fila.concept}
                                        </div>
                                    )}
                                </td>
                                <td className="p-3 text-right font-mono tabular-nums">
                                    {money(fila.amount)}
                                </td>
                                <td className="p-3 tabular-nums">
                                    {fila.incomeReceiptNumber ?? '—'}
                                </td>
                                <td className="p-3 text-right">
                                    <Button asChild variant="ghost" size="icon">
                                        <Link
                                            href={haberShow([
                                                fila.expedienteId,
                                                fila.haberNumber,
                                            ])}
                                            aria-label={`Ver el haber de ${fila.beneficiaryName}`}
                                        >
                                            <ChevronRight aria-hidden="true" />
                                        </Link>
                                    </Button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot className="border-t bg-muted/30">
                        <tr>
                            <td className="p-3 font-medium" colSpan={3}>
                                Total a entregar
                            </td>
                            <td className="p-3 text-right font-mono font-medium tabular-nums">
                                {money(total)}
                            </td>
                            <td className="p-3" colSpan={2} />
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    );
}

function Transferencias({
    filas,
    total,
}: {
    filas: TransferRow[];
    total: string;
}) {
    if (filas.length === 0) {
        return (
            <Vacio
                titulo="No hay transferencias esperando confirmación."
                detalle="Un egreso aparece acá desde que el organismo informa o el débito se reconoce, y sale cuando el contador lo valida."
            />
        );
    }

    return (
        <div className="grid gap-4">
            <div className="grid gap-3 md:hidden">
                {filas.map((fila) => (
                    <Link
                        key={fila.disbursementId}
                        href={haberShow([fila.expedienteId, fila.haberNumber])}
                        className="rounded-lg border p-3 transition-colors hover:bg-muted/40"
                    >
                        <div className="flex items-baseline justify-between gap-3">
                            <span className="font-medium">
                                {fila.beneficiaryName}
                            </span>
                            <span className="font-mono tabular-nums">
                                {money(fila.amount)}
                            </span>
                        </div>
                        <div className="mt-1 text-xs text-muted-foreground">
                            {fila.expedienteNumber} · {fila.installmentLabel}
                        </div>
                        <StatusBadge
                            className="mt-2"
                            label={ETIQUETA[fila.status] ?? fila.status}
                            tone={TONO[fila.status] ?? 'neutral'}
                        />
                    </Link>
                ))}
            </div>

            <div className="hidden overflow-x-auto rounded-lg border md:block">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="p-3 font-medium text-field-label">
                                Beneficiario
                            </th>
                            <th className="p-3 font-medium text-field-label">
                                Expediente
                            </th>
                            <th className="p-3 text-right font-medium text-field-label">
                                Importe
                            </th>
                            <th className="p-3 font-medium text-field-label">
                                Orden
                            </th>
                            <th className="p-3 font-medium text-field-label">
                                Informe
                            </th>
                            <th className="p-3 font-medium text-field-label">
                                Débito
                            </th>
                            <th className="p-3 font-medium text-field-label">
                                Estado
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {filas.map((fila) => (
                            <tr
                                key={fila.disbursementId}
                                className="border-t transition-colors hover:bg-muted/40"
                            >
                                <td className="p-3 font-medium">
                                    {fila.beneficiaryName}
                                </td>
                                <td className="p-3">
                                    <Link
                                        href={haberShow([
                                            fila.expedienteId,
                                            fila.haberNumber,
                                        ])}
                                        className="font-medium hover:underline"
                                    >
                                        {fila.expedienteNumber}
                                    </Link>
                                    <div className="text-xs text-muted-foreground">
                                        {fila.installmentLabel}
                                    </div>
                                </td>
                                <td className="p-3 text-right font-mono tabular-nums">
                                    {money(fila.amount)}
                                </td>
                                <td className="p-3 tabular-nums">
                                    {fila.paymentOrderNumber ?? '—'}
                                </td>
                                <td className="p-3 whitespace-nowrap">
                                    {fila.reportedAt
                                        ? date(fila.reportedAt)
                                        : '—'}
                                </td>
                                <td className="p-3 whitespace-nowrap">
                                    {fila.debitObservedAt
                                        ? date(fila.debitObservedAt)
                                        : '—'}
                                </td>
                                <td className="p-3">
                                    <StatusBadge
                                        label={
                                            ETIQUETA[fila.status] ?? fila.status
                                        }
                                        tone={TONO[fila.status] ?? 'neutral'}
                                    />
                                    {fila.waitingDays > 7 && (
                                        <span className="mt-1 flex items-center gap-1 text-xs text-warning-strong">
                                            <Clock className="size-3" />
                                            hace {fila.waitingDays} días
                                        </span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot className="border-t bg-muted/30">
                        <tr>
                            <td className="p-3 font-medium" colSpan={2}>
                                Total sin confirmar
                            </td>
                            <td className="p-3 text-right font-mono font-medium tabular-nums">
                                {money(total)}
                            </td>
                            <td className="p-3" colSpan={4} />
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    );
}

function Vacio({ titulo, detalle }: { titulo: string; detalle: string }) {
    return (
        <div className="rounded-lg border border-dashed p-10 text-center">
            <Banknote className="mx-auto size-8 text-muted-foreground" />
            <p className="mt-3 font-medium">{titulo}</p>
            <p className="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                {detalle}
            </p>
        </div>
    );
}

function Solapa({
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
