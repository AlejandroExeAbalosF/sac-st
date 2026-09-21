import { Link } from '@inertiajs/react';
import { Ban, ExternalLink, History, TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import Money from '@/components/money';
import {
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { useDrawer } from '@/features/drawer/drawer-context';
import { useIsMobile } from '@/hooks/use-mobile';
import { date, dateTime } from '@/lib/format';
import { show as expedienteShow } from '@/routes/expedientes';
import { show as haberShow } from '@/routes/haberes/haber';
import { history as historialDeCuota } from '@/routes/haberes/installments';
import { panel } from '@/routes/recibos';

type Panel = App.Modules.Haberes.Data.ReceiptPanelData;

const MEDIO: Record<string, string> = {
    cash: 'Efectivo',
    cheque: 'Cheque',
    bank: 'Depósito directo',
};

/**
 * De quién es el comprobante que se tocó en el libro del día.
 *
 * Pide los datos cada vez que se abre y no los guarda. El panel vive por
 * fuera de la pantalla y sobrevive a las navegaciones, así que lo que
 * cachee envejece sin aviso: un haber anulado seguiría figurando activo.
 * Por eso abajo dice a qué hora se consultó — el dato es de ese momento,
 * no de ahora.
 */
export default function ReceiptPanel({ receiptId }: { receiptId: number }) {
    const [datos, setDatos] = useState<Panel | null>(null);
    const [falló, setFalló] = useState(false);
    const [consultadoA, setConsultadoA] = useState<string | null>(null);

    /*
     * Sin reset del estado al empezar: el host remonta este componente por
     * `key` cuando cambia el recibo, así que arranca siempre en blanco.
     */
    useEffect(() => {
        let vigente = true;

        fetch(panel(receiptId).url, { headers: { Accept: 'application/json' } })
            .then((r) => {
                if (!r.ok) {
                    throw new Error(String(r.status));
                }

                return r.json();
            })
            .then((d: Panel) => {
                if (!vigente) {
                    return;
                }

                setDatos(d);
                setConsultadoA(new Date().toISOString());
            })
            .catch(() => vigente && setFalló(true));

        return () => {
            vigente = false;
        };
    }, [receiptId]);

    return (
        <>
            <SheetHeader className="border-b">
                <SheetTitle className="font-mono tabular-nums">
                    {datos
                        ? (datos.receipt.talonarioNumber ??
                          datos.receipt.formattedNumber)
                        : 'Comprobante'}
                </SheetTitle>
                <SheetDescription>
                    {datos
                        ? `${datos.receipt.status === 'voided' ? 'Anulado' : 'Emitido'} el ${date(datos.receipt.issueDate)}`
                        : 'Buscando el comprobante…'}
                </SheetDescription>
            </SheetHeader>

            <div className="flex-1 space-y-5 px-4 py-4">
                {falló && (
                    <p className="flex items-start gap-2 rounded-md border border-current bg-destructive-soft p-3 text-sm text-destructive-strong">
                        <TriangleAlert
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        No se pudo traer el comprobante. Cerrá el panel y volvé
                        a intentarlo.
                    </p>
                )}

                {!falló && datos === null && (
                    <div className="space-y-3">
                        <Skeleton className="h-16 w-full" />
                        <Skeleton className="h-28 w-full" />
                        <Skeleton className="h-20 w-full" />
                    </div>
                )}

                {datos && (
                    <>
                        {datos.receipt.status === 'voided' && (
                            <p className="flex items-start gap-2 rounded-md border border-destructive bg-destructive-soft px-3 py-2 text-sm text-destructive-strong">
                                <Ban
                                    className="mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <span>
                                    Anulado
                                    {datos.receipt.voidedAt &&
                                        ` el ${date(datos.receipt.voidedAt)}`}
                                    {datos.receipt.voidedByName &&
                                        ` por ${datos.receipt.voidedByName}`}
                                    {datos.receipt.voidReason && (
                                        <span className="mt-0.5 block">
                                            {datos.receipt.voidReason}
                                        </span>
                                    )}
                                </span>
                            </p>
                        )}

                        <Bloque titulo="El comprobante">
                            <Dato etiqueta="Importe">
                                <Money value={datos.receipt.amount} />
                            </Dato>
                            <Dato etiqueta="Medio">
                                {MEDIO[datos.medium] ?? datos.medium}
                            </Dato>
                            {datos.receipt.talonarioNumber && (
                                <Dato etiqueta="Nº del sistema">
                                    <span className="font-mono tabular-nums">
                                        {datos.receipt.formattedNumber}
                                    </span>
                                </Dato>
                            )}
                            {datos.concept && (
                                <Dato etiqueta="Concepto">{datos.concept}</Dato>
                            )}
                            {datos.counterpartyName && (
                                <Dato etiqueta="Recibí de">
                                    {datos.counterpartyName}
                                </Dato>
                            )}
                            {datos.receipt.issuedByName && (
                                <Dato etiqueta="Emitió">
                                    {datos.receipt.issuedByName}
                                </Dato>
                            )}
                        </Bloque>

                        {datos.subject ? (
                            <Sujeto
                                subject={datos.subject}
                                conceptoDelPapel={datos.concept}
                            />
                        ) : (
                            /*
                             * Un pago de haber anterior no cuelga de ninguna
                             * cuota: lo que el papel llama expediente es la
                             * referencia al registro manual, y no lleva a
                             * ninguna pantalla.
                             */
                            <Bloque titulo="A qué corresponde">
                                <Dato etiqueta="Beneficiario">
                                    {datos.beneficiaryNameOnPaper ?? '—'}
                                </Dato>
                                <Dato etiqueta="Referencia">
                                    {datos.expedienteNumberOnPaper ?? '—'}
                                </Dato>
                                <p className="col-span-2 text-xs text-muted-foreground">
                                    Es un pago de un haber anterior: se registró
                                    fuera del circuito y no tiene expediente en
                                    el sistema.
                                </p>
                            </Bloque>
                        )}
                    </>
                )}
            </div>

            {consultadoA && (
                <p className="border-t px-4 py-3 text-xs text-muted-foreground">
                    Consultado {dateTime(consultadoA)}
                </p>
            )}
        </>
    );
}

/**
 * A qué haber corresponde el comprobante, con a dónde ir desde acá.
 *
 * Es un componente propio y no un bloque más adentro del panel porque
 * recibe el sujeto ya resuelto: adentro no hay ningún `subject` que pueda
 * ser nulo, y por lo tanto ninguna aserción que lo niegue.
 */
function Sujeto({
    subject,
    conceptoDelPapel,
}: {
    subject: NonNullable<Panel['subject']>;
    conceptoDelPapel: string | null;
}) {
    const { openDrawer } = useDrawer();

    return (
        <Bloque titulo="A qué corresponde">
            <Dato etiqueta="Beneficiario">
                {subject.beneficiaryName}
                {subject.beneficiaryDocument && (
                    <span className="mt-0.5 block font-mono text-xs text-muted-foreground tabular-nums">
                        {subject.beneficiaryDocument}
                    </span>
                )}
            </Dato>

            {/*
             * El concepto solo si hoy dice otra cosa que el papel:
             * repetirlo dos veces en el mismo panel es ruido, y cuando
             * difiere es justo el dato que hay que ver.
             */}
            <Dato etiqueta="Cuota">
                {subject.installmentNumber}
                {subject.concept &&
                    subject.concept !== conceptoDelPapel &&
                    ` · ${subject.concept}`}
            </Dato>

            <Dato etiqueta="Empleador">{subject.employerName}</Dato>

            <div className="col-span-2 flex flex-wrap gap-2 pt-1">
                <Enlace
                    href={
                        haberShow([subject.expedienteId, subject.haberNumber])
                            .url
                    }
                >
                    Ver el haber
                </Enlace>
                <Enlace href={expedienteShow(subject.expedienteId).url}>
                    Expediente {subject.expedienteNumber}
                </Enlace>

                {/*
                 * El historial reemplaza el contenido del panel en vez de
                 * navegar: es la pregunta que sigue a «¿de quién es esto?»,
                 * y contestarla no debería costar perder la pantalla de
                 * caja. Es lo que se gana con un solo panel para todo.
                 */}
                <button
                    type="button"
                    onClick={() =>
                        openDrawer({
                            kind: 'history',
                            subject: 'installment',
                            url: historialDeCuota(subject.installmentId).url,
                            label: `de la cuota ${subject.installmentNumber}`,
                        })
                    }
                    className="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs font-medium transition-colors hover:bg-muted"
                >
                    <History className="size-3.5" aria-hidden="true" />
                    Historial de la cuota
                </button>
            </div>
        </Bloque>
    );
}

function Bloque({
    titulo,
    children,
}: {
    titulo: string;
    children: React.ReactNode;
}) {
    return (
        <section>
            <h3 className="mb-2 text-[0.6875rem] tracking-wide text-field-label uppercase">
                {titulo}
            </h3>
            <dl className="grid grid-cols-2 gap-x-4 gap-y-3 rounded-lg border p-3 text-sm">
                {children}
            </dl>
        </section>
    );
}

function Dato({
    etiqueta,
    children,
}: {
    etiqueta: string;
    children: React.ReactNode;
}) {
    return (
        <div className="min-w-0">
            <dt className="text-xs text-muted-foreground">{etiqueta}</dt>
            <dd className="mt-0.5 break-words">{children}</dd>
        </div>
    );
}

/**
 * Un enlace que, en pantalla chica, además cierra el panel.
 *
 * En el escritorio el panel convive con la pantalla de atrás y dejarlo
 * abierto es útil: se sigue viendo de qué comprobante se vino. En una
 * pantalla angosta ocupa el ancho completo, así que ir al haber lo dejaría
 * tapando justo lo que se fue a ver.
 *
 * El umbral es el de `useIsMobile`, que es la noción de «pantalla chica»
 * que el resto del sistema ya usa. No coincide exacto con el ancho al que
 * este panel pasa a ocupar todo, y no hace falta: entre uno y otro la
 * pantalla de atrás queda igual de tapada.
 */
function Enlace({
    href,
    children,
}: {
    href: string;
    children: React.ReactNode;
}) {
    const { closeDrawer } = useDrawer();
    const pantallaChica = useIsMobile();

    return (
        <Link
            href={href}
            onClick={() => pantallaChica && closeDrawer()}
            className="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs font-medium transition-colors hover:bg-muted"
        >
            <ExternalLink className="size-3.5" aria-hidden="true" />
            {children}
        </Link>
    );
}
