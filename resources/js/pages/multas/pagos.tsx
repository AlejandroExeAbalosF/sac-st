import { Head, Link } from '@inertiajs/react';
import Money from '@/components/money';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import { APPEAL_KIND, PAYMENT_MEDIUM } from '@/features/multas/labels';
import { date, dateTime } from '@/lib/format';
import { show } from '@/routes/multas';

type Pendiente = App.Modules.Multas.Data.FinePendingPaymentData;

/**
 * Pagos por verificar: lo informado por el banco que espera su crédito del
 * extracto, del más viejo al más nuevo.
 *
 * Cada fila lleva a la ficha de la multa, que es donde se verifica, se
 * corrige o se descarta.
 */
export default function PagosPorVerificar({
    informados,
}: {
    informados: Pendiente[];
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

                <section className="rounded-lg border bg-card shadow-raised">
                    <div className="flex items-center justify-between gap-4 border-b px-5 py-3">
                        <h2 className="text-sm font-semibold">
                            Informados por el banco
                        </h2>
                        <StatusBadge
                            label={String(informados.length)}
                            tone={informados.length > 0 ? 'action' : 'neutral'}
                        />
                    </div>
                    {informados.length === 0 ? (
                        <p className="px-5 py-4 text-sm text-muted-foreground">
                            No hay pagos informados esperando su crédito.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {informados.map((fila) => (
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
                                        {PAYMENT_MEDIUM[fila.payment.medium]}
                                        {fila.payment.appealKind &&
                                            ` · ${APPEAL_KIND[fila.payment.appealKind]}`}{' '}
                                        · <Money value={fila.payment.amount} />
                                    </span>
                                    <span className="w-full text-xs text-muted-foreground sm:ml-auto sm:w-auto">
                                        Presentado{' '}
                                        {fila.payment.presentedPrecision ===
                                        'instant'
                                            ? dateTime(fila.payment.presentedAt)
                                            : date(fila.payment.presentedAt)}
                                        {fila.payment.declaredPaidOn &&
                                            ` · pagado el ${date(fila.payment.declaredPaidOn)}`}
                                        {fila.payment.judicialReference &&
                                            ` · orden judicial ${fila.payment.judicialReference}`}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}
