import { Link, useForm, usePage } from '@inertiajs/react';
import {
    ArrowDownToLine,
    BadgeCheck,
    Ban,
    ChevronDown,
    FileText,
    FileUp,
    Landmark,
    Printer,
    Receipt,
    TriangleAlert,
    Undo2,
} from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import Money from '@/components/money';
import { Button } from '@/components/ui/button';
import { date, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { receiveAndAllocate as registrarIngreso } from '@/routes/haberes/installments';
import { create as nuevoTicket } from '@/routes/haberes/installments/ticket';
import { create as nuevoTraslado } from '@/routes/haberes/installments/transfer';
import { show as verRecepcion } from '@/routes/recepciones';
import CancelTransferDialog from './cancel-transfer-dialog';
import CreditMatchDialog from './credit-match-dialog';
import DepositTicketDialog from './deposit-ticket-dialog';
import IssueReceiptDialog from './issue-receipt-dialog';
import type { Firmante } from './issue-receipt-dialog';
import ReceiptViewerDialog from './receipt-viewer-dialog';
import UnallocateDialog from './unallocate-dialog';
import VoidCollectionDialog from './void-collection-dialog';

type Cuota = App.Modules.Haberes.Data.InstallmentListItemData;

/**
 * El tramo de ingreso de una cuota: qué entró, con qué papel y su recibo.
 *
 * Son tres cosas en orden y el orden es el del circuito real:
 *
 * 1. **Cuánto tiene financiado.** Sale de las asignaciones, calculado.
 * 2. **El comprobante del depósito**, cuando el dinero vino por banco.
 * 3. **El recibo de ingreso**, que se emite recién con la cuota completa
 *    (§2.1.14) y es la constancia de que el dinero ingresó.
 */
export default function InstallmentIncome({
    cuota,
    cuentas,
    puedeEditarTicket,
    puedeEmitirRecibo,
    puedeRegistrarPago,
    puedeTrasladar,
    puedeAcreditar,
    puedeDesasignar,
    puedeAnular,
    firmantes,
}: {
    cuota: Cuota;
    cuentas: { id: number; label: string }[];
    puedeEditarTicket: boolean;
    puedeEmitirRecibo: boolean;
    puedeRegistrarPago: boolean;
    puedeTrasladar: boolean;
    puedeAcreditar: boolean;
    puedeDesasignar: boolean;
    puedeAnular: boolean;
    firmantes: Firmante[];
}) {
    const anulada = cuota.status === 'cancelled';
    const sinFinanciar = Number(cuota.fundedAmount) <= 0;

    return (
        <div className="space-y-3">
            {(!sinFinanciar || Number(cuota.overAllocatedAmount) > 0) && (
                <Financiacion cuota={cuota} puedeDesasignar={puedeDesasignar} />
            )}

            <Comprobante
                cuota={cuota}
                cuentas={cuentas}
                puedeEditar={puedeEditarTicket}
            />

            {/*
             * **Ni el traslado ni el recibo se esconden con la cuota.**
             *
             * Un comprobante que se entregó y un depósito en tránsito no
             * dejan de existir porque alguien anule la cuota; esconderlos
             * dejaba plata en camino al banco sin forma de cerrarla. Lo
             * que sí se apaga con la anulación es **empezar** algo nuevo:
             * los permisos van en falso.
             */}
            <TrasladoAlBanco
                cuota={cuota}
                puedeTrasladar={puedeTrasladar && !anulada}
                puedeAcreditar={puedeAcreditar}
            />

            {/*
             * Los intentos anulados van afuera del recibo vigente: en una
             * cuota cuyo cobro se anuló del todo no queda ninguno, y es
             * justo donde la lista es la única explicación de lo que pasó.
             */}
            <RecibosAnulados cuota={cuota} />

            <TrasladosCancelados cuota={cuota} />

            <ReciboDeIngreso
                cuota={cuota}
                firmantes={firmantes}
                puedeAnular={puedeAnular}
                puedeRegistrarPago={puedeRegistrarPago}
                /*
                 * Con la cuota sin financiar el mismo boton cobra, y
                 * eso asienta dinero: hacen falta los dos permisos.
                 */
                puedeEmitir={
                    !anulada &&
                    puedeEmitirRecibo &&
                    (cuota.isFullyFunded || puedeRegistrarPago)
                }
            />
        </div>
    );
}

/**
 * Cuánto entró.
 *
 * **La barra aparece solo si la cuota está financiada a medias.** La regla
 * operativa es que cada cuota se deposita por su importe completo (§2.1.8),
 * así que el caso normal es entrar y salir al 100%: una barra llena en cada
 * cuota del plan no dice nada que el texto no diga, y compite por la
 * atención con lo que sí importa.
 *
 * El caso parcial existe —el modelo lo admite (§2.1.9)— pero es la
 * excepción, y ahí la barra sí informa de un vistazo cuánto falta.
 */
function Financiacion({
    cuota,
    puedeDesasignar,
}: {
    cuota: Cuota;
    puedeDesasignar: boolean;
}) {
    const [devolviendo, setDevolviendo] = useState(false);
    const sobra = Number(cuota.overAllocatedAmount) > 0;

    /*
     * El excedente se muestra, no se esconde.
     *
     * `remainingAmount` recorta los negativos a cero, asi que sin este
     * bloque la cuota diria «financiada por completo» con plata de mas
     * adentro. Pasa cuando se corrige el importe hacia abajo despues de
     * imputar, o cuando la recepcion fue a la cuota equivocada.
     *
     * No es un error del sistema: es un estado legitimo y transitorio, y
     * por eso trae con que resolverlo en vez de solo avisar.
     */
    if (sobra) {
        return (
            <>
                <div className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-destructive/40 bg-destructive-soft px-3 py-2">
                    <p className="flex items-center gap-1.5 text-xs">
                        <TriangleAlert className="size-3.5 shrink-0 text-destructive-strong" />
                        <span>
                            <strong className="font-medium text-destructive-strong">
                                Sobre-asignada en{' '}
                                {money(cuota.overAllocatedAmount)}
                            </strong>
                            <span className="text-muted-foreground">
                                {' '}
                                · tiene <Money
                                    value={cuota.fundedAmount}
                                />{' '}
                                imputados y espera{' '}
                                <Money value={cuota.expectedAmount} />
                            </span>
                        </span>
                    </p>
                    {puedeDesasignar && cuota.allocations.length > 0 && (
                        <Button
                            variant="outline"
                            size="sm"
                            className="min-h-11 text-xs sm:min-h-8"
                            onClick={() => setDevolviendo(true)}
                        >
                            <Undo2 className="size-3.5" />
                            Liberar el excedente
                        </Button>
                    )}
                </div>

                {devolviendo && (
                    <UnallocateDialog
                        cuota={cuota}
                        abierto
                        onCerrar={() => setDevolviendo(false)}
                    />
                )}
            </>
        );
    }

    if (cuota.isFullyFunded) {
        return (
            <p className="flex items-center gap-1.5 text-xs">
                <BadgeCheck className="size-3.5 shrink-0 text-success-strong" />
                <span className="font-medium text-success-strong">
                    Financiada por completo
                </span>
                <span className="text-muted-foreground">
                    · <Money value={cuota.fundedAmount} />
                </span>
            </p>
        );
    }

    const esperado = Number(cuota.expectedAmount);
    const financiado = Number(cuota.fundedAmount);
    const porcentaje =
        esperado > 0 ? Math.min(100, (financiado / esperado) * 100) : 0;

    /*
     * **En una cuota de mostrador, «financiada en parte» no existe.**
     *
     * El cobro toma el importe completo en un solo acto —el efectivo llega
     * con el expediente y se cuenta entero—, así que la única forma de
     * llegar acá es que el importe haya cambiado **después** de cobrar. No
     * es un cobro a medias: es que la cuota y lo cobrado dejaron de
     * coincidir.
     *
     * Mostrar una barra de progreso ahí dice lo contrario de lo que pasa:
     * sugiere que falta plata por entrar, cuando lo que falta es una
     * decisión. Por eso el circuito bancario conserva la barra —ahí un
     * crédito parcial es normal y uno espera el resto— y el de mostrador
     * muestra la diferencia con qué hacer.
     */
    const porMostrador =
        cuota.effectiveMedium === 'cash' || cuota.effectiveMedium === 'cheque';

    if (porMostrador) {
        return (
            <div className="space-y-1.5 rounded-md border border-warning/40 bg-warning/5 px-3 py-2">
                <p className="flex items-start gap-1.5 text-xs">
                    <TriangleAlert className="mt-0.5 size-3.5 shrink-0 text-warning-strong" />
                    <span>
                        <strong className="font-medium">
                            Lo cobrado y el importe de la cuota no coinciden
                        </strong>
                        <span className="text-muted-foreground">
                            {' '}
                            · se cobraron <Money value={cuota.fundedAmount} /> y
                            la cuota espera{' '}
                            <Money value={cuota.expectedAmount} />
                        </span>
                    </span>
                </p>

                <p className="pl-5 text-xs text-muted-foreground">
                    El efectivo se cobra entero en un solo acto, así que esto no
                    es un cobro a medias: el importe se corrigió después.
                    {cuota.incomeReceipt !== null && (
                        <>
                            {' '}
                            Para cobrar la diferencia hay que anular el recibo{' '}
                            <span className="font-mono">
                                {cuota.incomeReceipt.formattedNumber}
                            </span>{' '}
                            y volver a cobrar por el total.
                        </>
                    )}
                </p>
            </div>
        );
    }

    return (
        <div className="space-y-1.5">
            <div className="flex flex-wrap items-baseline justify-between gap-2 text-xs">
                <span className="font-medium">Financiada en parte</span>
                <span className="text-muted-foreground">
                    <Money value={cuota.fundedAmount} /> de{' '}
                    <Money value={cuota.expectedAmount} /> · faltan{' '}
                    {money(cuota.remainingAmount)}
                </span>
            </div>

            <div
                className="h-1.5 overflow-hidden rounded-full bg-muted"
                role="presentation"
            >
                <div
                    className="h-full rounded-full bg-info transition-all"
                    style={{ width: `${porcentaje}%` }}
                />
            </div>
        </div>
    );
}

/**
 * El recibo de ingreso.
 *
 * **No se ofrece hasta que la cuota está completa**: §2.1.14 es explícito
 * en que un ingreso parcial no genera comprobante de ningún tipo para el
 * empleador. Mostrar el botón antes sería invitar a un error que el Action
 * y la base van a rechazar igual.
 */
function ReciboDeIngreso({
    cuota,
    firmantes,
    puedeEmitir,
    puedeAnular,
    puedeRegistrarPago,
}: {
    cuota: Cuota;
    firmantes: Firmante[];
    puedeEmitir: boolean;
    puedeAnular: boolean;
    /** Para ofrecer el paso que falta cuando el crédito ya está cruzado. */
    puedeRegistrarPago: boolean;
}) {
    const [abierto, setAbierto] = useState(false);
    const [anulando, setAnulando] = useState(false);
    const recibo = cuota.incomeReceipt;
    const enTransito = cuota.cashTransfer?.status === 'deposited';

    /*
     * La clave viaja desde que se dibuja el botón, no desde que se aprieta:
     * es lo que hace que el segundo clic sea inocuo en vez de un segundo
     * ingreso asentado.
     */
    const [clave] = useState(
        () => `recibir:${cuota.id}:${crypto.randomUUID()}`,
    );

    const form = useForm({ idempotencyKey: clave });

    const { errors: errores } = usePage().props as unknown as {
        errors?: Record<string, string>;
    };

    const registrarYAsignar = () =>
        form.post(registrarIngreso(cuota.id).url, { preserveScroll: true });

    if (recibo !== null) {
        /*
         * El recibo dice lo que el empleador pagó; la cuota, lo que se le
         * debe al beneficiario. Son dos números y pueden diferir sin que
         * nada esté mal: el empleador pagó de más, o el importe de la
         * cuota se corrigió después de cobrar.
         *
         * **Se compara contra lo que la cuota espera, no contra lo
         * imputado.** Al subir el importe, lo imputado sigue coincidiendo
         * con el recibo y la diferencia real —la que el operador ve— queda
         * contra el importe nuevo. Comparar el par equivocado hacía que el
         * aviso no apareciera justo en el caso que lo necesita.
         *
         * Mostrar los dos números juntos sin decirlo se lee como un error
         * del sistema. El aviso no corrige nada: explica.
         */
        const difiere =
            recibo.amount !== cuota.expectedAmount ||
            recibo.amount !== cuota.fundedAmount;

        return (
            <div className="space-y-2">
                <div className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-success/40 bg-success-soft/40 px-3 py-2">
                    <p className="flex items-center gap-1.5 text-xs">
                        <BadgeCheck className="size-3.5 shrink-0 text-success-strong" />
                        <span>
                            Recibo{' '}
                            <strong className="font-mono">
                                {recibo.formattedNumber}
                            </strong>
                            {recibo.talonarioNumber && (
                                <span className="text-muted-foreground">
                                    {' '}
                                    · talonario {recibo.talonarioNumber}
                                </span>
                            )}
                            <span className="text-muted-foreground">
                                {' '}
                                · {date(recibo.issueDate)}
                            </span>
                        </span>
                    </p>

                    <div className="flex items-center gap-2">
                        <span className="font-mono text-xs tabular-nums">
                            {money(recibo.amount)}
                        </span>
                        {/*
                         * Un botón y no dos salidas sueltas. Antes había
                         * «Imprimir» y «Sobre el talonario», cada uno abriendo
                         * una pestaña con un PDF: para saber cuál era cuál
                         * había que abrir los dos. Acá se elige mirando.
                         */}
                        <Button
                            variant="outline"
                            size="sm"
                            className="min-h-11 text-xs sm:min-h-8"
                            onClick={() => setAbierto(true)}
                        >
                            <Printer className="size-3.5" />
                            Ver el recibo
                        </Button>

                        {/*
                         * Anular es para cuando el dinero nunca entró —un
                         * importe mal tipeado—. Está acá y no escondido porque
                         * es donde se descubre: mirando el recibo que dice un
                         * número que no coincide con lo que hay en la caja.
                         */}
                        {/*
                         * Con el efectivo camino al banco, anular se rechaza:
                         * el depósito ocurrió. Se oculta el botón en vez de
                         * dejar que el operador lo apriete para enterarse.
                         */}
                        {puedeAnular && !enTransito && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="h-8 text-xs text-destructive-strong hover:bg-destructive-soft"
                                onClick={() => setAnulando(true)}
                            >
                                <Ban className="size-3.5" />
                                Anular el recibo
                            </Button>
                        )}
                    </div>

                    <ReceiptViewerDialog
                        recibo={recibo}
                        abierto={abierto}
                        onCerrar={() => setAbierto(false)}
                    />
                </div>

                {anulando && (
                    <VoidCollectionDialog
                        cuota={cuota}
                        abierto
                        onCerrar={() => setAnulando(false)}
                    />
                )}

                {difiere && (
                    <p className="flex items-start gap-1.5 px-3 text-xs text-muted-foreground">
                        <TriangleAlert className="mt-0.5 size-3.5 shrink-0 text-warning-strong" />
                        <span>
                            El recibo documenta {money(recibo.amount)}, que es
                            lo que el empleador pagó, y no cambia. La cuota
                            espera {money(cuota.expectedAmount)} y tiene{' '}
                            {money(cuota.fundedAmount)} imputados.
                        </span>
                    </p>
                )}
            </div>
        );
    }

    /*
     * En el mostrador el recibo es la constancia del cobro: emitirlo
     * registra el ingreso. En el circuito bancario el dinero ya entro por
     * el extracto, y hasta que este asignado no hay comprobante que
     * emitir (§2.1.14).
     */
    const porMostrador =
        cuota.effectiveMedium === 'cash' || cuota.effectiveMedium === 'cheque';

    if (!cuota.isFullyFunded && !porMostrador) {
        /*
         * **Decir qué falta, no solo que falta.**
         *
         * El cartel anterior nombraba la condición —«se emite cuando la
         * cuota queda completamente financiada»— y ahí terminaba. Pero el
         * caso normal es que el comprobante ya esté cruzado con su crédito
         * del extracto, y entonces lo que falta es un paso concreto que el
         * sistema conoce: registrar la recepción de ese movimiento y
         * asignarla.
         *
         * Cruzar el ticket **no financia nada** (§2.1.13): dice que el
         * papel y el crédito son el mismo hecho, y nada más. Es una
         * distinción correcta del modelo que la pantalla tiene que
         * explicar, porque desde afuera parece que el trabajo ya está
         * hecho.
         */
        const ticket = cuota.depositTicket;
        const cruzado = ticket !== null && ticket.bankTransactionId !== null;
        const registrada = ticket?.fundReceiptId ?? null;

        return (
            <div className="flex items-start gap-2 text-xs text-muted-foreground">
                <Receipt className="mt-0.5 size-3.5 shrink-0" />
                <div className="space-y-1.5">
                    {registrada !== null ? (
                        <p>
                            El dinero ya está registrado como recepción, pero
                            todavía no se asignó a esta cuota: por eso figura
                            sin financiar. El recibo sale después de asignarlo.
                        </p>
                    ) : cruzado ? (
                        <p>
                            El comprobante ya está cruzado con su crédito del
                            extracto, pero ese dinero todavía no se registró
                            como recepción. Cruzar el ticket dice que el papel y
                            el crédito son el mismo hecho; no asienta la plata.
                        </p>
                    ) : ticket !== null ? (
                        <p>
                            El comprobante todavía no apareció en el extracto.
                            Hasta que el crédito no figure no hay recepción, y
                            sin recepción no hay recibo.
                        </p>
                    ) : (
                        <p>
                            El recibo de ingreso se emite cuando la cuota queda
                            completamente financiada. Le faltan{' '}
                            {money(cuota.remainingAmount)}.
                        </p>
                    )}

                    {cruzado && puedeRegistrarPago && (
                        <div className="flex flex-wrap items-center gap-2">
                            {/*
                             * Un clic hace los dos asientos. No fusiona
                             * hechos —el libro registra la recepción y la
                             * asignación por separado, como siempre— sino
                             * los clics: los importes los sabe el sistema,
                             * y hacerlos tipear era pedir dos veces un dato
                             * que ya está.
                             */}
                            <Button
                                size="sm"
                                className="min-h-11 text-xs sm:min-h-8"
                                disabled={form.processing}
                                onClick={registrarYAsignar}
                            >
                                <ArrowDownToLine className="size-3.5" />
                                {registrada !== null
                                    ? 'Asignar a la cuota'
                                    : 'Registrar el ingreso y asignarlo'}
                            </Button>

                            {/*
                             * El camino largo, para lo que el atajo no
                             * sabe: repartir la recepción entre cuotas de
                             * expedientes distintos.
                             *
                             * Solo cuando ya existe. El alta a mano se sacó
                             * —desvío 47— así que la recepción nace del
                             * atajo, y hasta que eso pase no hay nada que
                             * repartir.
                             */}
                            {registrada !== null && (
                                <Link
                                    href={verRecepcion(registrada).url}
                                    className="text-xs underline underline-offset-2 hover:text-primary"
                                >
                                    Repartirlo a mano
                                </Link>
                            )}
                        </div>
                    )}

                    <InputError message={errores?.installmentId} />
                </div>
            </div>
        );
    }

    return (
        <>
            <div className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-dashed px-3 py-2">
                <p className="text-xs">
                    {cuota.isFullyFunded
                        ? 'La cuota está completa: se puede emitir el recibo de ingreso.'
                        : 'El dinero ya vino con el expediente: el recibo lo asienta.'}
                </p>
                {puedeEmitir && (
                    <Button
                        size="sm"
                        className="min-h-11 text-xs sm:min-h-8"
                        onClick={() => setAbierto(true)}
                    >
                        <FileText className="size-3.5" />
                        {cuota.isFullyFunded
                            ? 'Generar recibo'
                            : 'Cobrar y emitir recibo'}
                    </Button>
                )}
            </div>

            <IssueReceiptDialog
                cuota={cuota}
                firmantes={firmantes}
                abierto={abierto}
                onCerrar={() => setAbierto(false)}
            />
        </>
    );
}

/**
 * El comprobante de depósito de la cuota.
 *
 * **Solo en las cuotas con Transferencia prevista.** Una que se espera en
 * efectivo o cheque entra por mostrador y no tiene ticket bancario que
 * cargar; ofrecerlo ahí sería invitar a registrar un papel que no existe.
 */
function Comprobante({
    cuota,
    cuentas,
    puedeEditar,
}: {
    cuota: Cuota;
    cuentas: { id: number; label: string }[];
    puedeEditar: boolean;
}) {
    const [abierto, setAbierto] = useState(false);
    const anulada = cuota.status === 'cancelled';
    const ticket = cuota.depositTicket;
    const porMostrador =
        cuota.effectiveMedium === 'cash' || cuota.effectiveMedium === 'cheque';

    if (ticket !== null) {
        const encontrado = ticket.status === 'matched';
        const Icono = encontrado ? BadgeCheck : Receipt;

        return (
            <>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="flex items-center gap-1.5 text-xs">
                        <Icono className="size-3.5 shrink-0 text-success-strong" />
                        {encontrado
                            ? 'Comprobante cargado y encontrado en el extracto.'
                            : 'Comprobante cargado, esperando aparecer en el extracto.'}
                    </p>
                    <Button
                        variant="ghost"
                        size="sm"
                        className="min-h-11 text-xs sm:min-h-8"
                        onClick={() => setAbierto(true)}
                    >
                        Ver comprobante
                    </Button>
                </div>

                <DepositTicketDialog
                    ticket={ticket}
                    cuentas={cuentas}
                    puedeEditar={puedeEditar}
                    abierto={abierto}
                    onCerrar={() => setAbierto(false)}
                />
            </>
        );
    }

    /*
     * Efectivo y cheque entran por mostrador (§2.5): no hay ticket
     * bancario que buscar. Cobrar tampoco es un acto aparte —el recibo lo
     * hace— asi que aca solo se enuncia el circuito.
     */
    if (porMostrador) {
        /*
         * Lo que decide el texto es si **ya se cobró**, no si la cuota
         * está completa. Mirar `isFullyFunded` hacía que una cuota cobrada
         * a la que después le subieron el importe volviera a decir «se
         * cobra por mostrador», contradiciendo al renglón de arriba que
         * dice cuánto se cobró.
         */
        const yaSeCobro = Number(cuota.fundedAmount) > 0;

        return (
            <p className="text-xs text-muted-foreground">
                {yaSeCobro
                    ? 'Esta cuota se cobró por mostrador: no lleva comprobante bancario.'
                    : 'Se cobra por mostrador: el pago se registra al emitir el recibo.'}
            </p>
        );
    }

    if (anulada || cuota.effectiveMedium !== 'bank') {
        return (
            <p className="text-xs text-muted-foreground">
                Sin comprobante de depósito cargado.
            </p>
        );
    }

    return (
        <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="text-xs text-muted-foreground">
                Si el expediente trajo el comprobante del depósito, cargalo acá.
            </p>
            <Button
                variant="outline"
                size="sm"
                className="min-h-11 text-xs sm:min-h-8"
                asChild
            >
                <Link href={nuevoTicket(cuota.id).url}>
                    <FileUp className="size-3.5" />
                    Cargar comprobante
                </Link>
            </Button>
        </div>
    );
}

/**
 * El efectivo que el beneficiario no retiró.
 *
 * Aparece recién con el recibo emitido, y solo en las cuotas de mostrador:
 * lo que entró por transferencia ya está en el banco. Son dos pasos porque
 * son dos hechos —el efectivo sale de la caja, y días después el extracto
 * lo confirma—, y entre uno y otro el dinero está en tránsito, que es un
 * lugar real y no un estado de trámite.
 */
function TrasladoAlBanco({
    cuota,
    puedeTrasladar,
    puedeAcreditar,
}: {
    cuota: Cuota;
    puedeTrasladar: boolean;
    puedeAcreditar: boolean;
}) {
    const [buscando, setBuscando] = useState(false);
    const [cancelando, setCancelando] = useState(false);

    const traslado = cuota.cashTransfer;
    const porMostrador =
        cuota.effectiveMedium === 'cash' || cuota.effectiveMedium === 'cheque';

    if (!porMostrador) {
        return null;
    }

    /*
     * **El traslado se muestra aunque no haya recibo vigente.**
     *
     * Antes se ocultaba con el recibo, y eso escondía un traslado en
     * tránsito: el efectivo salió de la caja y el banco todavía no lo
     * acreditó, pero la pantalla no daba con qué cerrarlo. Son dos hechos
     * independientes —el papel y el viaje al banco— y el segundo no deja
     * de existir porque el primero se anule.
     *
     * Lo que sí necesita el recibo es **empezar** un traslado: el orden
     * del circuito es cobrar, documentar y recién ahí depositar.
     */
    if (traslado === null && cuota.incomeReceipt === null) {
        return null;
    }

    if (traslado === null) {
        if (!puedeTrasladar) {
            return null;
        }

        return (
            <div className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-dashed px-3 py-2">
                <p className="text-xs text-muted-foreground">
                    Si el beneficiario no retira el efectivo, se deposita en la
                    cuenta del organismo.
                </p>
                {/*
                 * Va a una pantalla y no a un diálogo: transcribir el ticket
                 * del cajero necesita la foto al lado del formulario.
                 */}
                <Button
                    variant="outline"
                    size="sm"
                    className="min-h-11 text-xs sm:min-h-8"
                    asChild
                >
                    <Link href={nuevoTraslado(cuota.id)}>
                        <Landmark className="size-3.5" />
                        Depositar en el banco
                    </Link>
                </Button>
            </div>
        );
    }

    if (traslado.status === 'bank_confirmed') {
        return (
            <p className="flex items-center gap-1.5 rounded-md border border-success/40 bg-success-soft/40 px-3 py-2 text-xs">
                <Landmark className="size-3.5 shrink-0 text-success-strong" />
                <span>
                    Depositado el {date(traslado.depositDate)} y acreditado por
                    el extracto
                    {traslado.creditedDate !== null && (
                        <> el {date(traslado.creditedDate)}</>
                    )}
                    .
                </span>
            </p>
        );
    }

    return (
        <>
            <div className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-warning/40 bg-warning/5 px-3 py-2">
                <p className="flex items-center gap-1.5 text-xs">
                    <Landmark className="size-3.5 shrink-0" />
                    <span>
                        En tránsito desde el {date(traslado.depositDate)}: salió
                        de la caja y el banco todavía no lo acreditó.
                    </span>
                </p>
                {puedeAcreditar && (
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-8 text-xs text-destructive-strong hover:bg-destructive-soft"
                        onClick={() => setCancelando(true)}
                    >
                        <Undo2 className="size-3.5" />
                        Cancelar
                    </Button>
                )}
                {puedeAcreditar && (
                    <Button
                        variant="outline"
                        size="sm"
                        className="min-h-11 text-xs sm:min-h-8"
                        onClick={() => setBuscando(true)}
                    >
                        Buscar la acreditación
                    </Button>
                )}
            </div>

            {cancelando && (
                <CancelTransferDialog
                    traslado={traslado}
                    abierto
                    onCerrar={() => setCancelando(false)}
                />
            )}

            {buscando && (
                <CreditMatchDialog
                    traslado={traslado}
                    abierto
                    onCerrar={() => setBuscando(false)}
                />
            )}
        </>
    );
}

/**
 * Los intentos anulados de esta cuota.
 *
 * **Un comprobante anulado no desaparece**: consumió su número y alguien
 * lo tuvo en la mano. Verlos con su motivo es lo que explica por qué el
 * vigente tiene el número que tiene, y es la única forma de entender un
 * salto en la numeración sin salir a preguntar.
 *
 * Van del más nuevo al más viejo, que es la dirección en que se lee la
 * cadena de reemplazos.
 */
/**
 * Los depósitos que se cargaron y se dieron de baja.
 *
 * Existe por lo mismo que `RecibosAnulados`: la tarjeta trae solo el
 * traslado vigente, así que un depósito cargado y después cancelado
 * desaparecía entero y la cuota volvía a ofrecer «Depositar en el banco»
 * como si nada hubiera pasado. El rastro estaba en la base y no llegaba a
 * nadie.
 */
function TrasladosCancelados({ cuota }: { cuota: Cuota }) {
    const [abierto, setAbierto] = useState(false);
    const cancelados = cuota.cancelledTransfers;

    if (cancelados.length === 0) {
        return null;
    }

    return (
        <div className="px-3">
            <button
                type="button"
                onClick={() => setAbierto((v) => !v)}
                className="flex items-center gap-1.5 text-xs text-muted-foreground underline-offset-2 hover:underline"
            >
                <Ban className="size-3.5 shrink-0" aria-hidden="true" />
                {cancelados.length === 1
                    ? '1 depósito cargado y dado de baja'
                    : `${cancelados.length} depósitos cargados y dados de baja`}
                <ChevronDown
                    className={cn(
                        'size-3.5 transition-transform',
                        abierto && 'rotate-180',
                    )}
                    aria-hidden="true"
                />
            </button>

            {abierto && (
                <ul className="mt-2 space-y-1.5 border-l pl-3">
                    {cancelados.map((t) => (
                        <li key={t.id} className="text-xs">
                            <span className="text-muted-foreground line-through">
                                <Money value={t.amount} />
                            </span>
                            <span className="text-muted-foreground">
                                {' · '}
                                depositado el {date(t.depositDate)}
                                {t.cancelledAt !== null && (
                                    <>
                                        {' · '}
                                        dado de baja el{' '}
                                        {date(t.cancelledAt.slice(0, 10))}
                                    </>
                                )}
                                {t.cancelledByName !== null && (
                                    <> por {t.cancelledByName}</>
                                )}
                            </span>
                            {t.cancelReason !== null && (
                                <p className="text-muted-foreground italic">
                                    «{t.cancelReason}»
                                </p>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function RecibosAnulados({ cuota }: { cuota: Cuota }) {
    const [abierto, setAbierto] = useState(false);
    const anulados = cuota.voidedReceipts;

    if (anulados.length === 0) {
        return null;
    }

    return (
        <div className="px-3">
            <button
                type="button"
                onClick={() => setAbierto((v) => !v)}
                className="flex items-center gap-1.5 text-xs text-muted-foreground underline-offset-2 hover:underline"
            >
                <Ban className="size-3.5 shrink-0" />
                {anulados.length === 1
                    ? '1 recibo anulado antes de este'
                    : `${anulados.length} recibos anulados antes de este`}
                <ChevronDown
                    className={cn(
                        'size-3.5 transition-transform',
                        abierto && 'rotate-180',
                    )}
                />
            </button>

            {abierto && (
                <ul className="mt-2 space-y-1.5 border-l pl-3">
                    {anulados.map((r) => (
                        <li key={r.id} className="text-xs">
                            <span className="font-mono text-muted-foreground line-through">
                                {r.formattedNumber}
                            </span>
                            <span className="text-muted-foreground">
                                {' · '}
                                {money(r.amount)}
                                {r.voidedAt !== null && (
                                    <> · anulado el {date(r.voidedAt)}</>
                                )}
                                {r.voidedByName !== null && (
                                    <> por {r.voidedByName}</>
                                )}
                            </span>
                            {r.voidReason !== null && (
                                <p className="text-muted-foreground italic">
                                    «{r.voidReason}»
                                </p>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
