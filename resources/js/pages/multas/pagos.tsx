import { Head, Link } from '@inertiajs/react';
import Money from '@/components/money';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import { APPEAL_KIND, PAYMENT_MEDIUM } from '@/features/multas/labels';
import { date, dateTime } from '@/lib/format';
import { show } from '@/routes/multas';

type Pendiente = App.Modules.Multas.Data.FinePendingPaymentData;

/**
 * Pagos por verificar: lo informado que espera su crédito del extracto, y
 * los recursos cuya diferencia espera al administrador.
 *
 * Cada fila lleva a la ficha de la multa, que es donde se verifica, se
 * descarta o se resuelve.
 */
export default function PagosPorVerificar({
    informados,
    enRevision,
    canResolve,
}: {
    informados: Pendiente[];
    enRevision: Pendiente[];
    canResolve: boolean;
}) {
    return (
        <>
            <Head title="Pagos por verificar" />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Multas"
                    title="Pagos por verificar"
                    description="Lo informado por el banco todavía no reduce el saldo de la multa: se aplica al verificarlo contra el extracto."
                />

                <Bloque
                    titulo="Informados por el banco"
                    vacio="No hay pagos informados esperando su crédito."
                    filas={informados}
                    detalle={(p) => (
                        <>
                            Presentado{' '}
                            {p.payment.presentedPrecision === 'instant'
                                ? dateTime(p.payment.presentedAt)
                                : date(p.payment.presentedAt)}
                            {p.payment.declaredPaidOn &&
                                ` · pagado el ${date(p.payment.declaredPaidOn)}`}
                            {p.payment.judicialReference &&
                                ` · orden judicial ${p.payment.judicialReference}`}
                        </>
                    )}
                />

                <Bloque
                    titulo="Recursos en revisión"
                    vacio="No hay recursos con diferencia."
                    filas={enRevision}
                    aviso={
                        canResolve
                            ? undefined
                            : 'Los resuelve el administrador: revisa la multa, sus ajustes y el redondeo.'
                    }
                    detalle={(p) => (
                        <>
                            {p.payment.appealKind &&
                                APPEAL_KIND[p.payment.appealKind]}{' '}
                            · exigido{' '}
                            <Money
                                value={p.payment.appealRequiredAmount ?? '0.00'}
                            />{' '}
                            · el recibo externo dice{' '}
                            <Money
                                value={
                                    p.payment.externalReceiptAmount ?? '0.00'
                                }
                            />
                        </>
                    )}
                />
            </div>
        </>
    );
}

function Bloque({
    titulo,
    vacio,
    filas,
    detalle,
    aviso,
}: {
    titulo: string;
    vacio: string;
    filas: Pendiente[];
    detalle: (fila: Pendiente) => React.ReactNode;
    aviso?: string;
}) {
    return (
        <section className="rounded-lg border bg-card shadow-raised">
            <div className="flex items-center justify-between gap-4 border-b px-5 py-3">
                <h2 className="text-sm font-semibold">{titulo}</h2>
                <StatusBadge
                    label={String(filas.length)}
                    tone={filas.length > 0 ? 'action' : 'neutral'}
                />
            </div>
            {aviso && filas.length > 0 && (
                <p className="border-b px-5 py-2 text-xs text-muted-foreground">
                    {aviso}
                </p>
            )}
            {filas.length === 0 ? (
                <p className="px-5 py-4 text-sm text-muted-foreground">
                    {vacio}
                </p>
            ) : (
                <ul className="divide-y">
                    {filas.map((fila) => (
                        <li
                            key={fila.payment.id}
                            className="flex flex-wrap items-baseline gap-x-4 gap-y-1 px-5 py-3 text-sm"
                        >
                            <Link
                                href={show(fila.fineId)}
                                className="font-medium hover:underline"
                            >
                                {fila.fineCaption}
                            </Link>
                            <span className="font-mono text-xs text-muted-foreground">
                                Expte. {fila.caseLabel}
                            </span>
                            <span>
                                {PAYMENT_MEDIUM[fila.payment.medium]} ·{' '}
                                <Money value={fila.payment.amount} />
                            </span>
                            <span className="w-full text-xs text-muted-foreground sm:ml-auto sm:w-auto">
                                {detalle(fila)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
