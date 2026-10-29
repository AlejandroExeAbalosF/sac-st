import { Head, Link, router } from '@inertiajs/react';
import ListViewTab from '@/components/list-view-tab';
import Money from '@/components/money';
import PageHeader from '@/components/page-header';
import CollectionDeposit from '@/features/multas/components/collection-deposit';
import { APPEAL_KIND, PAYMENT_MEDIUM } from '@/features/multas/labels';
import { date, dateTime } from '@/lib/format';
import { index } from '@/routes/multas/pendientes';

type Pendiente = App.Modules.Multas.Data.FinePendingPaymentData;
type Pestana = 'informados' | 'depositar' | 'transito' | 'rechazos';

type Permisos = {
    verify: boolean;
    deposit: boolean;
    confirmDeposit: boolean;
    viewAttachments: boolean;
    manageRejections: boolean;
    replaceCheque: boolean;
};

/**
 * Lo que espera a alguien en Multas, por pestañas.
 *
 * - **Pagos informados:** lo que el deudor informó y espera su crédito del
 *   extracto. Cada fila lleva a la ficha, donde se verifica, se corrige o se
 *   descarta. Solo para quien concilia.
 * - **Por depositar** y **sin acreditar:** los cobros de la caja camino al
 *   banco, cada uno entero y en su propio depósito (M06).
 * - **Rechazos:** los cheques rechazados con algo pendiente (M03).
 *
 * Inicio cuenta cada pestaña y lleva directo a ella.
 */
export default function Pendientes({
    tab,
    informados,
    paraDepositar,
    enTransito,
    rechazados,
    can,
}: {
    tab: Pestana;
    informados: Pendiente[];
    paraDepositar: Pendiente[];
    enTransito: Pendiente[];
    rechazados: Pendiente[];
    can: Permisos;
}) {
    const pestanas: { valor: Pestana; titulo: string; filas: Pendiente[] }[] = [
        ...(can.verify
            ? [
                  {
                      valor: 'informados' as const,
                      titulo: 'Pagos informados',
                      filas: informados,
                  },
              ]
            : []),
        {
            valor: 'depositar',
            titulo: 'Por depositar',
            filas: paraDepositar,
        },
        { valor: 'transito', titulo: 'Sin acreditar', filas: enTransito },
        { valor: 'rechazos', titulo: 'Rechazos', filas: rechazados },
    ];

    const ir = (valor: Pestana) =>
        router.get(
            index({ query: { pestana: valor } }).url,
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );

    return (
        <>
            <Head title="Pendientes de Multas" />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Multas"
                    title="Pendientes"
                    description="Lo que espera a alguien: pagos informados sin su crédito, cobros de la caja camino al banco y cheques rechazados."
                />

                <nav
                    aria-label="Pendientes"
                    className="-mb-2 flex overflow-x-auto overflow-y-hidden border-b"
                >
                    {pestanas.map((p) => (
                        <ListViewTab
                            key={p.valor}
                            active={tab === p.valor}
                            onClick={() => ir(p.valor)}
                        >
                            {p.titulo}
                            <span className="ml-1.5 text-xs text-muted-foreground tabular-nums">
                                {p.filas.length}
                            </span>
                        </ListViewTab>
                    ))}
                </nav>

                {tab === 'informados' && <Informados filas={informados} />}
                {tab === 'depositar' && (
                    <Cobros
                        descripcion="Efectivo y cheques que siguen en la caja. Cada cobro va al banco entero y en su propio depósito."
                        vacio="No hay cobros en la caja esperando el depósito."
                        cobros={paraDepositar}
                        can={can}
                    />
                )}
                {tab === 'transito' && (
                    <Cobros
                        descripcion="Depositados, esperando que el extracto los confirme. La acreditación también se confirma desde Banco › Movimientos."
                        vacio="No hay depósitos esperando al extracto."
                        cobros={enTransito}
                        can={can}
                    />
                )}
                {/*
                 * Un rechazo sigue acá mientras falte algo: el reemplazo de
                 * la empresa, un hecho del banco o los papeles.
                 */}
                {tab === 'rechazos' && (
                    <Cobros
                        descripcion="Cheques rechazados con el reemplazo, un hecho del banco o los papeles todavía pendientes."
                        vacio="No hay cheques rechazados con algo pendiente."
                        cobros={rechazados}
                        can={can}
                    />
                )}
            </div>
        </>
    );
}

/**
 * Lo informado que espera su crédito, del más viejo al más nuevo: todavía
 * no reduce el saldo de la multa.
 */
function Informados({ filas }: { filas: Pendiente[] }) {
    return (
        <section className="rounded-lg border bg-card shadow-raised">
            <p className="border-b px-5 py-3 text-xs text-muted-foreground">
                Lo informado todavía no reduce el saldo de la multa: se aplica
                al verificarlo contra el extracto, desde la ficha o desde Banco
                › Movimientos.
            </p>
            {filas.length === 0 ? (
                <p className="px-5 py-4 text-sm text-muted-foreground">
                    No hay pagos informados esperando su crédito.
                </p>
            ) : (
                <ul className="divide-y">
                    {filas.map((fila) => (
                        <li
                            key={fila.payment.id}
                            className="flex flex-wrap items-baseline gap-x-4 gap-y-1 px-5 py-3 text-sm"
                        >
                            <Link
                                href={fila.fineHref}
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
                                {fila.payment.presentedPrecision === 'instant'
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
    );
}

/**
 * Cobros de la caja en un mismo lugar, del más viejo al más nuevo, con lo
 * que se puede hacer con cada uno: depositarlo, confirmar su acreditación o
 * seguir su rechazo.
 */
function Cobros({
    descripcion,
    vacio,
    cobros,
    can,
}: {
    descripcion: string;
    vacio: string;
    cobros: Pendiente[];
    can: Permisos;
}) {
    return (
        <section className="rounded-lg border bg-card shadow-raised">
            <p className="border-b px-5 py-3 text-xs text-muted-foreground">
                {descripcion}
            </p>
            {cobros.length === 0 ? (
                <p className="px-5 py-4 text-sm text-muted-foreground">
                    {vacio}
                </p>
            ) : (
                <ul className="divide-y">
                    {cobros.map((fila) => (
                        <li
                            key={fila.payment.id}
                            className="grid gap-2 px-5 py-3 text-sm"
                        >
                            <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                                <Link
                                    href={fila.fineHref}
                                    className="font-medium hover:underline"
                                >
                                    {fila.fineCaption}
                                </Link>
                                <span className="font-mono text-xs text-muted-foreground">
                                    Expte. {fila.caseLabel}
                                </span>
                                <span>
                                    {PAYMENT_MEDIUM[fila.payment.medium]}
                                    {(fila.payment.deposit?.chequeNumber ??
                                        fila.payment.chequeNumber) &&
                                        ` n.º ${fila.payment.deposit?.chequeNumber ?? fila.payment.chequeNumber}`}{' '}
                                    · <Money value={fila.payment.amount} />
                                </span>
                                <span className="w-full text-xs text-muted-foreground sm:ml-auto sm:w-auto">
                                    Recibo{' '}
                                    {fila.payment.talonarioNumber ??
                                        fila.payment.receiptNumber ??
                                        '—'}
                                    {fila.payment.receiptDate &&
                                        ` del ${date(fila.payment.receiptDate)}`}
                                </span>
                            </div>
                            {fila.payment.deposit && (
                                <CollectionDeposit
                                    paymentId={fila.payment.id}
                                    amount={fila.payment.amount}
                                    deposito={fila.payment.deposit}
                                    desde="pendientes"
                                    canDeposit={can.deposit}
                                    canConfirm={can.confirmDeposit}
                                    canViewAttachments={can.viewAttachments}
                                    isCheque={fila.payment.medium === 'cheque'}
                                    canManageRejections={can.manageRejections}
                                    canReplaceCheque={can.replaceCheque}
                                />
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
