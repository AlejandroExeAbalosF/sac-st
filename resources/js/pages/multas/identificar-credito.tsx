import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import ListViewTab from '@/components/list-view-tab';
import Money from '@/components/money';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import type { Firmante } from '@/features/multas/components/collect-payment-dialog';
import { FieldError } from '@/features/multas/components/form-dialog';
import IdentifyCreditForm from '@/features/multas/components/identify-credit-form';
import { PAYMENT_MEDIUM } from '@/features/multas/labels';
import { date } from '@/lib/format';
import { confirm as acreditarDeposito } from '@/routes/multas/traslados';

type Credito = App.Modules.Multas.Data.FineBankCreditData;
type Pendiente = App.Modules.Multas.Data.FinePendingPaymentData;
type Camino = 'pago' | 'deposito';

/**
 * Un crédito de la cuenta de Multas que nadie identificó, desde Banco ›
 * Movimientos.
 *
 * Es una de dos cosas (M08): el pago de una multa —informado o no— o la
 * acreditación de un depósito de la caja. Abre en el camino que tiene algo
 * del mismo importe para ofrecer. Al terminar, vuelve a la lista.
 */
export default function IdentificarCredito({
    credit,
    reported,
    deposits,
    firmantes,
    backHref,
}: {
    credit: Credito;
    reported: Pendiente[];
    deposits: Pendiente[];
    firmantes: Firmante[];
    backHref: string;
}) {
    const [camino, setCamino] = useState<Camino>(
        deposits.length > 0 && reported.length === 0 ? 'deposito' : 'pago',
    );

    return (
        <>
            <Head title="Identificar un crédito" />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Banco · cuenta de Multas"
                    title={`Crédito del ${date(credit.date)}`}
                    description={[
                        credit.counterparty,
                        credit.operationId && `operación ${credit.operationId}`,
                        credit.description,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={backHref}>
                                <ArrowLeft className="size-4" />
                                Movimientos
                            </Link>
                        </Button>
                    }
                />

                <p className="text-2xl font-semibold">
                    <Money value={credit.amount} />
                </p>

                <nav
                    aria-label="Qué es el crédito"
                    className="-mb-2 flex overflow-x-auto overflow-y-hidden border-b"
                >
                    <ListViewTab
                        active={camino === 'pago'}
                        onClick={() => setCamino('pago')}
                    >
                        Pago de una multa
                    </ListViewTab>
                    <ListViewTab
                        active={camino === 'deposito'}
                        onClick={() => setCamino('deposito')}
                    >
                        Depósito de la caja
                        <span className="ml-1.5 text-xs text-muted-foreground tabular-nums">
                            {deposits.length}
                        </span>
                    </ListViewTab>
                </nav>

                <section className="rounded-lg border bg-card p-5 shadow-raised">
                    {camino === 'pago' ? (
                        <IdentifyCreditForm
                            credito={credit}
                            informados={reported}
                            firmantes={firmantes}
                        />
                    ) : (
                        <DepositosDeLaCaja credito={credit} cobros={deposits} />
                    )}
                </section>
            </div>
        </>
    );
}

/**
 * Los cobros de la caja depositados por el mismo importe, esperando al
 * extracto: un depósito lleva un cobro entero (M06). Confirmar no crea otro
 * movimiento: dice que este crédito es el depósito que salió de la caja.
 */
function DepositosDeLaCaja({
    credito,
    cobros,
}: {
    credito: Credito;
    cobros: Pendiente[];
}) {
    const [clave] = useState(
        () => `acreditar:${credito.id}:${crypto.randomUUID()}`,
    );
    const { post, transform, processing, errors } = useForm({});
    const errores = errors as Record<string, string | undefined>;

    if (cobros.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No hay depósitos de la caja por <Money value={credito.amount} />{' '}
                esperando al extracto. Si el crédito es el pago de una multa,
                identificalo en la otra pestaña.
            </p>
        );
    }

    const confirmar = (traslado: number) => {
        transform(() => ({
            bankTransactionId: credito.id,
            idempotencyKey: clave,
        }));
        post(acreditarDeposito(traslado).url);
    };

    return (
        <div className="grid gap-3">
            <p className="text-sm text-muted-foreground">
                Depósitos de la caja por el mismo importe, esperando que el
                extracto los confirme.
            </p>
            <ul className="divide-y rounded-md border">
                {cobros.map((fila) => {
                    const deposito = fila.payment.deposit;

                    return (
                        <li
                            key={fila.payment.id}
                            className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 text-sm"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="font-medium">
                                    {fila.fineCaption}{' '}
                                    <span className="font-mono text-xs font-normal text-muted-foreground">
                                        Expte. {fila.caseLabel}
                                    </span>
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {PAYMENT_MEDIUM[fila.payment.medium]} ·{' '}
                                    <Money value={fila.payment.amount} />
                                    {deposito?.depositDate &&
                                        ` · depositado el ${date(deposito.depositDate)}`}
                                    {deposito?.operationNumber &&
                                        ` · ticket ${deposito.operationNumber}`}
                                </p>
                            </div>
                            {deposito?.transferId && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={processing}
                                    onClick={() =>
                                        confirmar(deposito.transferId as number)
                                    }
                                >
                                    Es este depósito
                                </Button>
                            )}
                        </li>
                    );
                })}
            </ul>
            <FieldError message={errores.bankTransactionId} />
        </div>
    );
}
