import { Head, Link } from '@inertiajs/react';
import {
    CalendarDays,
    FileSpreadsheet,
    History,
    Lock,
    LockOpen,
    RefreshCw,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import Money, { EnMoneda, useMoneda } from '@/components/money';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    DialogoCierre,
    DialogoHistorialDePlanillas,
    DialogoReapertura,
    DialogoRehacerPlanilla,
} from '@/features/caja/components/closing-dialogs';
import SelectorDeMoneda from '@/features/caja/components/currency-switch';
import { conMoneda } from '@/features/caja/moneda';
import { date as formatDate } from '@/lib/format';
import type { CurrencyCode } from '@/lib/format';
import { calendario, dia as caja } from '@/routes/caja';
import { index as cierres, sheet } from '@/routes/caja/cierres';

type Cierre = App.Modules.Ledger.Data.PeriodClosingListItemData;

type Props = {
    selected: {
        cashBoxId: number;
        cashBoxName: string;
        currency: CurrencyCode;
        defaultDate: string;
    };
    /** Días con movimientos sin cerrar, por mes `YYYY-MM`. */
    pendingDaysByMonth: Record<string, number>;
    closings: Cierre[];
    can: {
        viewDay: boolean;
        close: boolean;
        reopen: boolean;
        export: boolean;
        regenerate: boolean;
    };
    /** La versión con la que dibuja el generador de hoy. */
    sheetVersion: string;
};

/**
 * Cierres de período.
 *
 * Cada fila es una planilla: apertura, movimientos y saldo final por
 * columna. Se muestra el cuadro entero y no solo el total porque quien
 * revisa un cierre no quiere el resultado, quiere ver de dónde salió.
 */
export default function CajaCierres({
    selected,
    pendingDaysByMonth,
    closings,
    can,
    sheetVersion,
}: Props) {
    /** Solo una fila reabierta abre el diálogo, con su tipo y fecha. */
    const [cerrando, setCerrando] = useState<{
        date: string;
        periodType: string;
    } | null>(null);

    /** El cierre cuya planilla se está por rehacer, si hay alguno. */
    const [rehaciendo, setRehaciendo] = useState<Cierre | null>(null);

    /** Y aquel cuyas planillas emitidas se están mirando. */
    const [mirandoPlanillas, setMirandoPlanillas] = useState<Cierre | null>(
        null,
    );
    const [reabriendo, setReabriendo] = useState<Cierre | null>(null);

    return (
        <EnMoneda moneda={selected.currency}>
            <Head title="Cierres de caja" />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Caja"
                    title="Cierres de período"
                    description="Congelan los totales del período y traban las operaciones retroactivas."
                    actions={
                        <>
                            <SelectorDeMoneda
                                moneda={selected.currency}
                                href={(otra) => conMoneda(cierres().url, otra)}
                            />
                            <Button variant="outline" asChild>
                                <Link
                                    href={conMoneda(
                                        calendario().url,
                                        selected.currency,
                                    )}
                                >
                                    <CalendarDays className="size-4" />
                                    Ver como calendario
                                </Link>
                            </Button>
                        </>
                    }
                />

                {closings.length === 0 ? (
                    <div className="flex flex-col items-center gap-4 rounded-lg border bg-card px-4 py-12 text-center">
                        <p className="text-sm text-muted-foreground">
                            Todavía no se cerró ningún período.
                        </p>
                        {can.viewDay && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={conMoneda(
                                        caja({
                                            query: {
                                                fecha: selected.defaultDate,
                                            },
                                        }).url,
                                        selected.currency,
                                    )}
                                >
                                    Ir a Caja del día
                                </Link>
                            </Button>
                        )}
                    </div>
                ) : (
                    <div className="grid gap-4">
                        {closings.map((cierre) => (
                            <Tarjeta
                                key={cierre.id}
                                cierre={cierre}
                                can={can}
                                sheetVersion={sheetVersion}
                                onRehacer={() => setRehaciendo(cierre)}
                                onVerPlanillas={() =>
                                    setMirandoPlanillas(cierre)
                                }
                                onReabrir={() => setReabriendo(cierre)}
                                onCerrar={() =>
                                    setCerrando({
                                        date: cierre.periodFrom,
                                        periodType: cierre.periodType,
                                    })
                                }
                            />
                        ))}
                    </div>
                )}
            </div>

            {cerrando && (
                <DialogoCierre
                    key={`${cerrando.periodType}-${cerrando.date}`}
                    inicial={cerrando}
                    cerrar={() => setCerrando(null)}
                    selected={selected}
                    pendingDaysByMonth={pendingDaysByMonth}
                />
            )}

            <DialogoReapertura
                cierre={reabriendo}
                cerrar={() => setReabriendo(null)}
            />

            <DialogoRehacerPlanilla
                cierre={rehaciendo}
                cerrar={() => setRehaciendo(null)}
            />

            <DialogoHistorialDePlanillas
                cierre={mirandoPlanillas}
                cerrar={() => setMirandoPlanillas(null)}
            />
        </EnMoneda>
    );
}

function Tarjeta({
    cierre,
    can,
    sheetVersion,
    onRehacer,
    onVerPlanillas,
    onReabrir,
    onCerrar,
}: {
    cierre: Cierre;
    can: Props['can'];
    sheetVersion: string;
    onRehacer: () => void;
    onVerPlanillas: () => void;
    onReabrir: () => void;
    onCerrar: () => void;
}) {
    const cerrado = cierre.status === 'closed';
    const unDia = cierre.periodFrom === cierre.periodTo;

    /*
     * La planilla guardada quedó atrás del generador.
     *
     * Los números no cambian --el snapshot del cierre está congelado-- y
     * por eso el archivo se ve igual de oficial. Lo único que cambió es
     * el dibujo, y sin este aviso no hay forma de notarlo. Una planilla
     * emitida antes de que se marcaran las versiones tampoco puede
     * afirmar que esté al día.
     */
    const moneda = useMoneda();
    const planillaAtrasada =
        cerrado &&
        cierre.sheetAttachmentId !== null &&
        cierre.sheetTemplateVersion !== sheetVersion;

    return (
        <article className="overflow-hidden rounded-lg border bg-card">
            <header className="flex flex-wrap items-center gap-3 border-b px-4 py-3">
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <span className="rounded border px-1.5 py-0.5 text-[0.65rem] font-medium tracking-wide text-muted-foreground uppercase">
                            {cierre.periodTypeLabel}
                        </span>
                        <h2 className="text-sm font-semibold">
                            {unDia
                                ? formatDate(cierre.periodFrom)
                                : `${formatDate(cierre.periodFrom)} — ${formatDate(cierre.periodTo)}`}
                        </h2>
                    </div>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {cerrado && cierre.closedBy
                            ? `Cerró ${cierre.closedBy}`
                            : !cerrado && cierre.reopenedBy
                              ? `Reabrió ${cierre.reopenedBy}`
                              : cierre.statusLabel}
                    </p>
                </div>

                <StatusBadge
                    label={cierre.statusLabel}
                    tone={cerrado ? 'done' : 'action'}
                />

                <div className="ml-auto flex flex-wrap gap-2">
                    {/*
                     * El diario reabierto vuelve a Caja porque necesita un
                     * arqueo nuevo. El mensual, que no cuenta un cajón,
                     * conserva el cierre directo con sus fechas completas.
                     */}
                    {can.close &&
                        !cerrado &&
                        (unDia ? (
                            <Button size="sm" asChild>
                                <Link
                                    href={conMoneda(
                                        caja({
                                            query: {
                                                fecha: cierre.periodFrom,
                                            },
                                        }).url,
                                        moneda,
                                    )}
                                >
                                    <Lock className="size-4" />
                                    Recontar y cerrar
                                </Link>
                            </Button>
                        ) : (
                            <Button size="sm" onClick={onCerrar}>
                                <Lock className="size-4" />
                                Cerrar
                            </Button>
                        ))}
                    {can.export && cerrado && (
                        <Button variant="outline" size="sm" asChild>
                            <a href={sheet({ closing: cierre.id }).url}>
                                <FileSpreadsheet className="size-4" />
                                Planilla
                            </a>
                        </Button>
                    )}
                    {/*
                     * El historial aparece recién con la segunda versión:
                     * con una sola, el botón «Planilla» ya la entrega y
                     * ofrecer «ver el historial» de un único archivo sería
                     * un clic que no lleva a ningún lado.
                     */}
                    {can.export && cierre.sheetHistory.length > 1 && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={onVerPlanillas}
                        >
                            <History className="size-4" />
                            {cierre.sheetHistory.length} versiones
                        </Button>
                    )}
                    {can.regenerate && cerrado && cierre.sheetAttachmentId && (
                        <Button
                            variant={planillaAtrasada ? 'default' : 'outline'}
                            size="sm"
                            onClick={onRehacer}
                        >
                            <RefreshCw className="size-4" />
                            Rehacer la planilla
                        </Button>
                    )}
                    {can.reopen && cerrado && (
                        <Button variant="outline" size="sm" onClick={onReabrir}>
                            <LockOpen className="size-4" />
                            Reabrir
                        </Button>
                    )}
                </div>
            </header>

            {/*
             * El snapshot dejó de coincidir con el libro.
             *
             * Un cierre se calcula del libro y se guarda; si después entró
             * un movimiento con fecha anterior, el guardado quedó
             * mintiendo. Hoy eso ya no puede pasar --el asiento se
             * rechaza-- pero los datos anteriores a esa regla pueden
             * traerlo, y un cierre desactualizado se ve idéntico a uno
             * sano: sin este aviso no hay forma de encontrarlo.
             */}
            {cierre.matchesLedger === false && (
                <p className="mx-4 mt-3 flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-xs text-destructive">
                    <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                    <span>
                        Los saldos congelados ya no coinciden con el libro:
                        entró un movimiento con fecha anterior después de
                        cerrar. Reabrí el período y volvé a cerrarlo para
                        recalcularlos.
                    </span>
                </p>
            )}

            {planillaAtrasada && (
                <p className="mx-4 mt-3 flex items-start gap-2 rounded-md border border-warning-soft bg-warning-soft px-3 py-2 text-xs text-warning-strong">
                    <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                    <span>
                        La planilla guardada se dibujó con una versión anterior
                        del formulario. Los números son los mismos —el cierre
                        está congelado—, pero el formato no es el que emite el
                        sistema hoy.
                    </span>
                </p>
            )}

            {/* Las tres columnas de la planilla, en el mismo orden. */}
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="text-xs tracking-wide text-muted-foreground uppercase">
                        <tr>
                            <th className="px-4 py-2 text-left font-medium" />
                            <th className="px-4 py-2 text-right font-medium">
                                Efectivo
                            </th>
                            <th className="px-4 py-2 text-right font-medium">
                                Cheques
                            </th>
                            <th className="px-4 py-2 text-right font-medium">
                                Depósitos directos
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        <Fila
                            rotulo="Saldo inicial"
                            valores={[
                                cierre.openingCash,
                                cierre.openingCheques,
                                cierre.openingBankDeposits,
                            ]}
                        />
                        <Fila
                            rotulo="Ingresos"
                            valores={[cierre.receivedCash, null, null]}
                        />
                        <Fila
                            rotulo="Egresos"
                            valores={[cierre.disbursedCash, null, null]}
                        />
                        <Fila
                            rotulo="Depositado al banco"
                            valores={[cierre.depositedToBankCash, null, null]}
                        />
                        <Fila
                            rotulo="Saldo final"
                            valores={[
                                cierre.closingCash,
                                cierre.closingCheques,
                                cierre.closingBankDeposits,
                            ]}
                            fuerte
                        />
                    </tbody>
                </table>
            </div>

            {cierre.reopenReason && (
                <p className="border-t bg-warning-soft px-4 py-2 text-xs text-warning-strong">
                    Reabierto{cierre.reopenedBy && ` por ${cierre.reopenedBy}`}:{' '}
                    {cierre.reopenReason}
                </p>
            )}
        </article>
    );
}

function Fila({
    rotulo,
    valores,
    fuerte = false,
}: {
    rotulo: string;
    valores: [string, string | null, string | null];
    fuerte?: boolean;
}) {
    return (
        <tr className={fuerte ? 'bg-muted/40 font-semibold' : undefined}>
            <td className="px-4 py-2">{rotulo}</td>
            {valores.map((valor, i) => (
                <td key={i} className="px-4 py-2 text-right">
                    {valor === null ? (
                        <span className="text-muted-foreground">—</span>
                    ) : (
                        <Money value={valor} dimWhenZero={!fuerte} />
                    )}
                </td>
            ))}
        </tr>
    );
}

/**
 * Rehacer la planilla de un cierre, con el dibujo de hoy.
 *
 * **No corrige números.** El snapshot del cierre está congelado y sigue
 * igual; lo que se rehace es el archivo. Cuando lo que está mal son los
 * importes, el camino es reabrir, recontar y cerrar.
 *
 * El Excel anterior no se pisa: `attachments` rechaza toda edición por
 * trigger. Queda archivado, porque pudo imprimirse y firmarse.
 */
