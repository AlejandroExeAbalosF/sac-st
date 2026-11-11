import { router } from '@inertiajs/react';
import {
    ChevronDown,
    CircleCheck,
    CornerDownRight,
    Landmark,
    SearchX,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { date, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
    debits as candidatos,
    linkDebit as vincular,
} from '@/routes/haberes/installments/disbursement';

type Cuota = App.Modules.Haberes.Data.InstallmentListItemData;
type Estado = App.Modules.Haberes.Data.InstallmentDisbursementData;

type Respuesta = { candidates: Candidato[]; others: Otro[] };

type Candidato = {
    id: number;
    date: string | null;
    amount: string;
    description: string | null;
    operationId: string | null;
    causalCode: string | null;
    /** El importe de la transferencia, en positivo. */
    transferAmount: string;
    /** Transferencia + comisión: lo que el par descuenta de la Orden. */
    total: string;
    /** La comisión de la misma operación, que se vincula con la transferencia. */
    fee: {
        id: number;
        causalCode: string | null;
        amount: string;
        description: string | null;
    } | null;
    /** Si cierra con la Orden pero no se puede elegir, por qué. */
    reviewReason: string | null;
    signals: Record<string, string>;
};

/** Un débito sin conciliar de la cuenta que no es este pago, y por qué. */
type Otro = Omit<Candidato, 'reviewReason' | 'signals'> & { reason: string };

/**
 * El débito del extracto con que el organismo pagó — §2.3.2.
 *
 * **Propone, no vincula.** Confirmar es de una persona porque un débito
 * mal atribuido daría por pagada una cuota con la transferencia de otra, y
 * dejaría a un trabajador esperando un dinero que el sistema cree
 * entregado.
 *
 * Reconocerlo tiene un segundo efecto que no se ve: al validar, ese
 * movimiento queda sin saldo libre y deja de ofrecerse para otra Orden.
 * Sin eso, la misma transferencia podría pagar dos cuotas.
 *
 * **El pago puede ser dos renglones.** Si el banco cobró comisión por la
 * transferencia, la absorbe el beneficiario: transferencia + comisión
 * suman la Orden. Se elige la transferencia y la comisión viene con ella;
 * el servidor ya la encontró por la operación. Por eso la comisión se
 * muestra como renglón propio, debajo y con sangría, y se marca junto con
 * su transferencia: no se elige ni se quita por separado.
 *
 * **Un solo par, no una suma de varios.** A diferencia de un ingreso, que se
 * arma con varios créditos, un egreso se paga con una transferencia —y su
 * comisión— que cierran exacto con la Orden. El pie muestra la selección
 * contra lo que hay que pagar, como en la conciliación de ingresos, pero se
 * completa de un clic.
 */
export default function TransferDebitDialog({
    cuota,
    estado,
    abierto,
    onCerrar,
}: {
    cuota: Cuota;
    estado: Estado;
    abierto: boolean;
    onCerrar: () => void;
}) {
    const [datos, setDatos] = useState<Respuesta | null>(null);
    const [fallo, setFallo] = useState(false);
    const [verOtros, setVerOtros] = useState(false);
    const [elegido, setElegido] = useState<number | null>(null);
    const [enviando, setEnviando] = useState(false);
    const seleccion = datos?.candidates.find((c) => c.id === elegido) ?? null;

    /*
     * Se monta al abrirse, así que el estado nace limpio y el efecto solo
     * busca. Vaciarlo acá adentro sería pedirle a React que renderice dos
     * veces para llegar al mismo lugar.
     */
    useEffect(() => {
        let vigente = true;

        fetch(candidatos(cuota.id).url, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => {
                /*
                 * Un error del servidor no es «no hay débitos»: decirlo así
                 * mandaría a buscar un extracto que quizás ya está importado.
                 */
                if (!r.ok) {
                    throw new Error(`HTTP ${r.status}`);
                }

                return r.json() as Promise<Respuesta>;
            })
            .then((r) => {
                if (vigente) {
                    setDatos(r);
                }
            })
            .catch(() => {
                if (vigente) {
                    setFallo(true);
                }
            });

        return () => {
            vigente = false;
        };
    }, [cuota.id]);

    const confirmar = () => {
        if (elegido === null) {
            return;
        }

        setEnviando(true);

        router.post(
            vincular(cuota.id).url,
            { bankTransactionId: elegido },
            {
                preserveScroll: true,
                onFinish: () => setEnviando(false),
                onSuccess: () => onCerrar(),
            },
        );
    };

    return (
        <Dialog open={abierto} onOpenChange={(v) => !v && onCerrar()}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>El débito en el extracto</DialogTitle>
                    <DialogDescription>
                        Elegí la transferencia con que pagó el organismo. Si el
                        banco cobró comisión, se vincula con ella y la absorbe
                        el beneficiario.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-wrap items-baseline justify-between gap-2 rounded-lg bg-muted/50 px-3 py-2 text-sm">
                    <span className="text-muted-foreground">
                        Orden {estado.orderNumber ?? ''}
                    </span>
                    <span>
                        A pagar{' '}
                        <span className="font-mono font-semibold tabular-nums">
                            {money(estado.amount)}
                        </span>
                    </span>
                </div>

                <div
                    role="radiogroup"
                    aria-label="Débitos que pueden ser el pago"
                    className="mt-2 max-h-[50vh] space-y-2 overflow-y-auto"
                >
                    {fallo && (
                        <p className="rounded-lg border border-dashed p-4 text-sm text-destructive">
                            No se pudo buscar en el extracto. Volvé a
                            intentarlo; si sigue fallando, avisale al
                            administrador del sistema.
                        </p>
                    )}

                    {datos === null && !fallo && (
                        <div className="flex items-center gap-2 py-8 text-sm text-muted-foreground">
                            <Spinner className="size-4" />
                            Buscando en el extracto…
                        </div>
                    )}

                    {datos !== null && datos.candidates.length === 0 && (
                        <div className="space-y-2 rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                            <p className="flex items-center gap-2 font-medium text-foreground">
                                <SearchX
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                No hay ningún débito que coincida
                            </p>
                            {/*
                             * Casi nunca significa que la transferencia no
                             * exista: lo más común es que el extracto de
                             * ese período todavía no se haya importado.
                             */}
                            <p>
                                Lo más probable es que el extracto del período
                                todavía no esté importado. También puede ser que
                                el organismo no haya transferido aún, o que la
                                transferencia y su comisión no sumen el importe
                                de la Orden.
                            </p>
                        </div>
                    )}

                    {datos?.candidates.map((c) => {
                        const revision = c.reviewReason !== null;
                        const marcado = elegido === c.id;

                        return (
                            <button
                                key={c.id}
                                type="button"
                                role="radio"
                                aria-checked={marcado}
                                /*
                                 * Uno para revisión se ve, pero no se elige:
                                 * el importe cierra y el extracto no alcanza
                                 * para afirmar cuál es la comisión.
                                 */
                                disabled={revision}
                                onClick={() => setElegido(c.id)}
                                className={cn(
                                    'w-full rounded-lg border p-3 text-left transition-colors',
                                    revision
                                        ? 'cursor-not-allowed border-dashed opacity-80'
                                        : marcado
                                          ? 'border-primary bg-primary/5'
                                          : 'hover:bg-muted/50',
                                )}
                            >
                                <Renglon
                                    marcado={marcado}
                                    fecha={c.date}
                                    operacion={c.operationId}
                                    causal={c.causalCode}
                                    concepto={c.description}
                                    importe={c.transferAmount}
                                />

                                {c.fee !== null && (
                                    <div className="mt-1.5 ml-6 flex items-start gap-1.5 border-l pl-3">
                                        <CornerDownRight
                                            className="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <Renglon
                                            marcado={marcado}
                                            concepto={`Comisión bancaria${c.fee.causalCode ? ` · ${c.fee.causalCode}` : ''}`}
                                            detalle="A cargo del beneficiario"
                                            importe={c.fee.amount}
                                            secundario
                                        />
                                    </div>
                                )}

                                {revision && (
                                    <p className="mt-2 text-xs text-warning-strong">
                                        {c.reviewReason}
                                    </p>
                                )}

                                {/*
                                 * Las señales en palabras y no un puntaje: un
                                 * «85 %» obliga a confiar, esto deja decidir.
                                 */}
                                <div className="mt-2 flex flex-wrap gap-1.5">
                                    {Object.entries(c.signals).map(([k, v]) => (
                                        <span
                                            key={k}
                                            className="rounded bg-muted px-1.5 py-0.5 text-[0.7rem] text-muted-foreground"
                                        >
                                            {k}: {v}
                                        </span>
                                    ))}
                                </div>
                            </button>
                        );
                    })}

                    {/*
                     * Para entender por qué no aparece el pago: los demás
                     * débitos sin conciliar de la cuenta, con el motivo que
                     * da la misma regla que vincula. Solo se consultan.
                     */}
                    {datos !== null && datos.others.length > 0 && (
                        <div className="pt-2">
                            <button
                                type="button"
                                onClick={() => setVerOtros((v) => !v)}
                                aria-expanded={verOtros}
                                className="flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                            >
                                <ChevronDown
                                    className={cn(
                                        'size-4 transition-transform',
                                        verOtros && 'rotate-180',
                                    )}
                                    aria-hidden="true"
                                />
                                Ver otros débitos sin conciliar de la cuenta (
                                {datos.others.length})
                            </button>

                            {verOtros && (
                                <ul className="mt-2 space-y-2">
                                    {datos.others.map((o) => (
                                        <li
                                            key={o.id}
                                            className="rounded-lg border border-dashed p-3"
                                        >
                                            <Renglon
                                                marcado={false}
                                                fecha={o.date}
                                                operacion={o.operationId}
                                                causal={o.causalCode}
                                                concepto={o.description}
                                                importe={o.transferAmount}
                                                consulta
                                            />

                                            {o.fee !== null && (
                                                <div className="mt-1.5 ml-6 flex items-start gap-1.5 border-l pl-3">
                                                    <CornerDownRight
                                                        className="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                                                        aria-hidden="true"
                                                    />
                                                    <Renglon
                                                        marcado={false}
                                                        concepto={`Comisión bancaria${o.fee.causalCode ? ` · ${o.fee.causalCode}` : ''}`}
                                                        importe={o.fee.amount}
                                                        secundario
                                                    />
                                                </div>
                                            )}

                                            <p className="mt-2 text-xs text-muted-foreground">
                                                {o.reason}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    )}
                </div>

                {/*
                 * La selección contra lo que hay que pagar, como en la
                 * conciliación de ingresos. Todo candidato elegible cierra
                 * exacto, así que con uno elegido la Orden queda cubierta.
                 */}
                <div
                    className="flex flex-wrap items-center justify-between gap-2 border-t pt-3 text-sm"
                    aria-live="polite"
                >
                    {seleccion === null ? (
                        <span className="text-muted-foreground">
                            Sin elegir · faltan {money(estado.amount)}
                        </span>
                    ) : (
                        <>
                            <span className="font-mono tabular-nums">
                                Seleccionado {money(seleccion.transferAmount)}
                                {seleccion.fee !== null &&
                                    ` + ${money(seleccion.fee.amount)}`}{' '}
                                = {money(seleccion.total)}
                            </span>
                            <span className="flex items-center gap-1 font-medium text-success-strong">
                                <CircleCheck
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Completa
                            </span>
                        </>
                    )}
                </div>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={onCerrar}>
                        Cancelar
                    </Button>
                    <Button
                        type="button"
                        onClick={confirmar}
                        disabled={elegido === null || enviando}
                    >
                        <Landmark className="size-4" />
                        Es este débito
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Un renglón del extracto dentro del card: la transferencia, o su comisión
 * debajo. Los dos se marcan juntos.
 */
function Renglon({
    marcado,
    fecha,
    operacion,
    causal,
    concepto,
    detalle,
    importe,
    secundario = false,
    consulta = false,
}: {
    marcado: boolean;
    fecha?: string | null;
    operacion?: string | null;
    causal?: string | null;
    concepto: string | null;
    detalle?: string;
    importe: string;
    secundario?: boolean;
    /** Solo para consultar: sin círculo de selección. */
    consulta?: boolean;
}) {
    const datos = [
        fecha === undefined ? null : fecha === null ? 'Sin fecha' : date(fecha),
        operacion ? `Op. ${operacion}` : null,
    ].filter((d): d is string => d !== null);

    return (
        <div className="flex min-w-0 flex-1 items-start justify-between gap-3">
            <div className="flex min-w-0 items-start gap-2">
                {!secundario && !consulta && (
                    <span
                        className={cn(
                            'mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border',
                            marcado
                                ? 'border-primary'
                                : 'border-muted-foreground/50',
                        )}
                        aria-hidden="true"
                    >
                        {marcado && (
                            <span className="size-2 rounded-full bg-primary" />
                        )}
                    </span>
                )}
                <div className="min-w-0">
                    {datos.length > 0 && (
                        <p className="flex items-center gap-1.5 text-sm font-medium">
                            <Landmark
                                className="size-3.5 text-muted-foreground"
                                aria-hidden="true"
                            />
                            {datos.join(' · ')}
                        </p>
                    )}
                    <p
                        className={cn(
                            'text-xs',
                            secundario
                                ? 'font-medium text-foreground'
                                : 'mt-0.5 text-muted-foreground',
                        )}
                    >
                        {concepto ?? 'Sin concepto'}
                        {causal && !secundario ? ` · ${causal}` : ''}
                    </p>
                    {detalle !== undefined && (
                        <p className="text-xs text-muted-foreground">
                            {detalle}
                        </p>
                    )}
                </div>
            </div>
            <span
                className={cn(
                    'shrink-0 font-mono tabular-nums',
                    secundario ? 'text-xs' : 'text-sm font-semibold',
                    secundario && marcado && 'text-primary',
                )}
            >
                {money(importe)}
            </span>
        </div>
    );
}
