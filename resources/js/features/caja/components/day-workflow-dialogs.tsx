import { useForm, usePage } from '@inertiajs/react';
import { Check, Lock, Scale } from 'lucide-react';
import InputError from '@/components/input-error';
import Money from '@/components/money';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import DetalleComposicionDelFajo from '@/features/caja/components/carry-composition';
import { date as formatDate } from '@/lib/format';
import { adjust, review } from '@/routes/caja/arqueos';
import { store as cerrar } from '@/routes/caja/cierres';

type Arqueo = App.Modules.Ledger.Data.CashCountListItemData;

/**
 * Los arqueos del día, con todo lo que la tarjeta no tiene lugar de mostrar.
 * El último turno respalda el cierre; los anteriores explican cómo se llegó
 * a él y permanecen como historia inmutable.
 */
export function DialogoDetalle({
    arqueos,
    referenciaComposicion,
    cerrar,
}: {
    arqueos: Arqueo[];
    referenciaComposicion: Arqueo | null;
    cerrar: () => void;
}) {
    const delMasNuevo = [...arqueos].reverse();

    return (
        <Dialog open onOpenChange={(open) => open || cerrar()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {arqueos.length > 1
                            ? `Arqueos del día (${arqueos.length})`
                            : 'Detalle del arqueo'}
                    </DialogTitle>
                    <DialogDescription>
                        El último turno es el que respalda el cierre. Los
                        anteriores quedan como historia y no se editan.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-4">
                    {delMasNuevo.map((item, indice) => (
                        <DetalleDeUnArqueo
                            key={item.id}
                            arqueo={item}
                            referenciaComposicion={referenciaComposicion}
                            vigente={indice === 0}
                        />
                    ))}
                </div>

                <DialogFooter>
                    <Button variant="outline" type="button" onClick={cerrar}>
                        Cerrar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function DetalleDeUnArqueo({
    arqueo,
    referenciaComposicion,
    vigente,
}: {
    arqueo: Arqueo;
    referenciaComposicion: Arqueo | null;
    vigente: boolean;
}) {
    return (
        <div
            className={
                vigente
                    ? 'rounded-lg border bg-card p-4'
                    : 'rounded-lg border border-dashed p-4 opacity-80'
            }
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm font-semibold">Turno {arqueo.sequence}</p>
                <span className="text-xs text-muted-foreground">
                    {arqueo.statusLabel}
                    {arqueo.adjusted && ' · diferencia imputada'}
                </span>
            </div>

            <dl className="mt-3 space-y-1.5 text-sm">
                <FilaRevision
                    termino="Saldo del libro"
                    valor={arqueo.expectedAmount}
                />
                <FilaRevision
                    termino="Efectivo contado"
                    valor={arqueo.countedAmount}
                />
                {!arqueo.fullyCounted && (
                    <FilaRevision
                        termino="No recontado"
                        valor={arqueo.uncountedAmount}
                    />
                )}
                <div className="border-t pt-1.5">
                    <FilaRevision
                        termino="Diferencia"
                        valor={arqueo.differenceAmount}
                        fuerte
                    />
                </div>
            </dl>

            {arqueo.carryRecountReason !== null && (
                <p className="mt-3 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    Recaudación del día
                </p>
            )}

            {arqueo.lines.length > 0 ? (
                <table className="mt-2 w-full text-sm">
                    <thead>
                        <tr className="text-xs text-muted-foreground">
                            <th className="text-left font-normal">Billete</th>
                            <th className="text-right font-normal">Cantidad</th>
                            <th className="text-right font-normal">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {arqueo.lines.map((linea) => (
                            <tr key={linea.denomination}>
                                <td className="py-1">
                                    <Money value={linea.denomination} />
                                </td>
                                <td className="py-1 text-right tabular-nums">
                                    {linea.quantity}
                                </td>
                                <td className="py-1 text-right">
                                    <Money value={linea.subtotal} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            ) : (
                arqueo.carryRecountReason !== null && (
                    <p className="mt-2 text-xs text-muted-foreground">
                        No se registraron billetes como recaudación del día.
                    </p>
                )
            )}

            {arqueo.carryRecountReason !== null && (
                <div className="mt-3">
                    <DetalleComposicionDelFajo
                        arqueo={arqueo}
                        referencia={referenciaComposicion}
                    />
                </div>
            )}

            {/*
             * Una diferencia sin su explicación es un número suelto: es la
             * mitad del arqueo.
             */}
            {arqueo.explanation && (
                <Observacion
                    termino="Observaciones"
                    texto={arqueo.explanation}
                />
            )}

            <dl className="mt-3 grid gap-1 border-t pt-3 text-xs text-muted-foreground">
                <div className="flex justify-between gap-4">
                    <dt>Contó</dt>
                    <dd className="text-right">{arqueo.performedBy ?? '—'}</dd>
                </div>
                <div className="flex justify-between gap-4">
                    <dt>Revisó</dt>
                    <dd className="text-right">
                        {arqueo.reviewedBy ?? 'Sin revisar'}
                        {arqueo.selfReviewed && ' (sin segunda firma)'}
                    </dd>
                </div>
            </dl>
        </div>
    );
}

function Observacion({ termino, texto }: { termino: string; texto: string }) {
    return (
        <div className="mt-3 rounded-md bg-muted/50 px-3 py-2">
            <p className="text-xs font-medium text-muted-foreground">
                {termino}
            </p>
            <p className="mt-0.5 text-sm">{texto}</p>
        </div>
    );
}

export function DialogoRevision({
    arqueo,
    anterior,
    referenciaComposicion,
    cerrar,
    volverAContar,
}: {
    arqueo: Arqueo;
    /**
     * El arqueo anterior, para comparar.
     *
     * Va acá y no en el modal de conteo a propósito: comparar después de
     * contar ayuda, y verlo antes ancla. El que cuenta no tiene que saber
     * a qué número llegar.
     */
    anterior: Arqueo | null;
    referenciaComposicion: Arqueo | null;
    cerrar: () => void;
    /** Cierra esta revisión y abre el conteo, que es a dónde se vuelve. */
    volverAContar: () => void;
}) {
    const form = useForm({});
    const { auth } = usePage().props;
    const autorrevision = arqueo.performedById === auth.user.id;
    const errores = form.errors as Record<string, string>;

    const confirmar = () => {
        form.post(review({ cashCount: arqueo.id }).url, {
            preserveScroll: true,
            onSuccess: cerrar,
        });
    };

    return (
        <Dialog open onOpenChange={(open) => open || cerrar()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Revisar y cerrar el arqueo</DialogTitle>
                    <DialogDescription>
                        Confirmá los importes antes de firmarlo. Después de la
                        revisión el conteo queda como evidencia y ya no se puede
                        editar.
                    </DialogDescription>
                </DialogHeader>

                <dl className="grid gap-2 rounded-lg border bg-muted/40 px-4 py-3 text-sm">
                    <FilaRevision
                        termino="Saldo del libro"
                        valor={arqueo.expectedAmount}
                    />
                    <FilaRevision
                        termino="Efectivo contado"
                        valor={arqueo.countedAmount}
                    />
                    {!arqueo.fullyCounted && (
                        <FilaRevision
                            termino="No recontado"
                            valor={arqueo.uncountedAmount}
                        />
                    )}
                    <div className="border-t pt-2">
                        <FilaRevision
                            termino="Diferencia"
                            valor={arqueo.differenceAmount}
                            fuerte
                        />
                    </div>
                </dl>

                {/*
                 * El arqueo anterior, para comparar.
                 *
                 * Va acá y no en el modal de conteo a propósito: comparar
                 * después de contar ayuda, y verlo antes ancla. El que
                 * cuenta no tiene que saber a qué número llegar.
                 */}
                {anterior !== null && (
                    <p className="px-1 text-xs text-muted-foreground">
                        Arqueo anterior ({formatDate(anterior.countedOn)}):
                        contado <Money value={anterior.countedAmount} />
                        {!anterior.fullyCounted && (
                            <>
                                {' '}
                                · sin recontar{' '}
                                <Money value={anterior.uncountedAmount} />
                            </>
                        )}
                    </p>
                )}

                {arqueo.carryRecountReason !== null && (
                    <DetalleComposicionDelFajo
                        arqueo={arqueo}
                        referencia={referenciaComposicion}
                    />
                )}

                {/*
                 * Quién contó y quién firma, con nombre y apellido.
                 *
                 * Antes acá había un párrafo explicando qué es la doble
                 * firma y por qué el sistema la deja pasar. Los dos
                 * nombres dicen lo mismo en un renglón: si son el mismo,
                 * se ve solo.
                 */}
                <dl className="grid gap-1 px-1 text-sm">
                    <div className="flex justify-between gap-4">
                        <dt className="text-muted-foreground">Contó</dt>
                        <dd className="text-right font-medium">
                            {arqueo.performedBy ?? '—'}
                        </dd>
                    </div>
                    <div className="flex justify-between gap-4">
                        <dt className="text-muted-foreground">Firma</dt>
                        <dd className="text-right font-medium">
                            {auth.user.name}
                            {autorrevision && (
                                <span className="ml-1.5 font-normal text-muted-foreground">
                                    (sin segunda firma)
                                </span>
                            )}
                        </dd>
                    </div>
                </dl>

                <InputError
                    message={
                        errores.status ?? errores.lines ?? errores.explanation
                    }
                />

                <DialogFooter>
                    {/*
                     * «Volver» cierra y deja todo como está; «Volver al
                     * conteo» abre el conteo, que es lo que su nombre
                     * promete. Antes los dos hacían lo mismo —cerrar— y el
                     * segundo no llevaba a ningún lado.
                     */}
                    <Button variant="ghost" type="button" onClick={cerrar}>
                        Volver
                    </Button>
                    <Button
                        variant="outline"
                        type="button"
                        onClick={volverAContar}
                    >
                        <Scale className="size-4" />
                        Volver al conteo
                    </Button>
                    <Button
                        type="button"
                        disabled={form.processing}
                        onClick={confirmar}
                    >
                        {form.processing ? (
                            <Spinner />
                        ) : (
                            <Check className="size-4" />
                        )}
                        {form.processing ? 'Revisando…' : 'Confirmar revisión'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function FilaRevision({
    termino,
    valor,
    fuerte = false,
}: {
    termino: string;
    valor: string;
    fuerte?: boolean;
}) {
    return (
        <div className="flex items-baseline justify-between gap-4">
            <dt className={fuerte ? 'font-medium' : 'text-muted-foreground'}>
                {termino}
            </dt>
            <dd>
                <Money
                    value={valor}
                    className={fuerte ? 'font-semibold' : undefined}
                />
            </dd>
        </div>
    );
}

/**
 * La confirmación del cierre.
 *
 * Congela los totales y traba las operaciones retroactivas, así que el
 * diálogo dice qué se está por congelar antes de pedir el botón. Cuando el
 * arqueo quedó sin segunda firma, lo avisa. Si tuvo una diferencia, también
 * distingue si sigue pendiente o si ya quedó regularizada con su asiento.
 */
export function DialogoCierreDelDia({
    fecha,
    cashBoxId,
    currency,
    arqueo,
    recierre,
    cerrar: cerrarDialogo,
}: {
    fecha: string;
    cashBoxId: number;
    currency: string;
    arqueo: Arqueo | null;
    recierre: boolean;
    cerrar: () => void;
}) {
    const form = useForm({
        cashBoxId,
        date: fecha,
        periodType: 'daily',
        currency,
        notes: '',
    });

    const diferenciaPendiente =
        arqueo !== null && !arqueo.balanced && !arqueo.adjusted;
    const diferenciaImputada = arqueo?.adjusted ?? false;
    const sinSegundaFirma = arqueo?.selfReviewed ?? false;

    return (
        <Dialog open onOpenChange={(open) => open || cerrarDialogo()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {recierre ? 'Volver a cerrar' : 'Cerrar'} el día{' '}
                        {formatDate(fecha)}
                    </DialogTitle>
                    <DialogDescription>
                        Los totales se calculan desde el libro y quedan
                        congelados. A partir de acá el día no admite movimientos
                        con esa fecha.
                    </DialogDescription>
                </DialogHeader>

                {arqueo && (
                    <div className="rounded-lg border bg-muted/40 px-4 py-3 text-sm">
                        <p className="flex items-baseline justify-between gap-4">
                            <span className="text-muted-foreground">
                                {diferenciaImputada
                                    ? 'Diferencia imputada'
                                    : 'Diferencia del arqueo'}
                            </span>
                            <Money
                                value={arqueo.differenceAmount}
                                className="font-semibold"
                            />
                        </p>

                        {diferenciaPendiente && (
                            <p className="mt-2 text-xs text-warning-strong">
                                El arqueo conserva una diferencia sin imputar.
                                Imputala o volvé a contar el cajón antes de
                                cerrar el día.
                            </p>
                        )}

                        {diferenciaImputada && (
                            <p className="mt-2 text-xs text-info-strong">
                                La diferencia ya fue imputada y quedó registrada
                                en la cuenta de diferencias. El arqueo está
                                regularizado para cerrar el día.
                            </p>
                        )}

                        {sinSegundaFirma && (
                            <p className="mt-2 text-xs text-muted-foreground">
                                {arqueo?.reviewedBy
                                    ? `Contó y revisó ${arqueo.reviewedBy}: queda registrado sin segunda firma.`
                                    : 'El arqueo lo revisó la misma persona que contó: queda registrado sin segunda firma.'}
                            </p>
                        )}
                    </div>
                )}

                <div className="grid gap-2">
                    <Label htmlFor="notes">Observaciones</Label>
                    <Input
                        id="notes"
                        value={form.data.notes}
                        onChange={(e) => form.setData('notes', e.target.value)}
                        placeholder="Opcional"
                    />
                    <InputError message={form.errors.notes} />
                    <InputError message={form.errors.date} />
                    <InputError
                        message={(form.errors as Record<string, string>).period}
                    />
                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        type="button"
                        onClick={cerrarDialogo}
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="button"
                        disabled={form.processing || diferenciaPendiente}
                        onClick={() =>
                            form.post(cerrar().url, {
                                preserveScroll: true,
                                onSuccess: cerrarDialogo,
                            })
                        }
                    >
                        {form.processing ? (
                            <Spinner />
                        ) : (
                            <Lock className="size-4" />
                        )}
                        {form.processing
                            ? 'Cerrando…'
                            : recierre
                              ? 'Volver a cerrar el día'
                              : 'Cerrar el día'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** Imputar la diferencia mueve plata contra `CASH_DIFFERENCE`. */
export function DialogoImputacion({
    arqueo,
    cerrar: cerrarDialogo,
}: {
    arqueo: Arqueo;
    cerrar: () => void;
}) {
    const form = useForm({ authorization: '' });
    const esFaltante = arqueo.differenceAmount.startsWith('-');
    const importe = esFaltante
        ? arqueo.differenceAmount.slice(1)
        : arqueo.differenceAmount;

    return (
        <Dialog open onOpenChange={(open) => open || cerrarDialogo()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Imputar la diferencia</DialogTitle>
                    <DialogDescription>
                        Registra un ajuste contable por{' '}
                        <Money value={importe} /> correspondiente al{' '}
                        {esFaltante ? 'faltante' : 'sobrante'}, para que el
                        saldo del libro coincida con el efectivo contado. No
                        registra un cobro ni un pago. Tu usuario y la fecha
                        quedan registrados.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-2">
                    <Label htmlFor="authorization">
                        Referencia de autorización (opcional)
                    </Label>
                    <Input
                        id="authorization"
                        value={form.data.authorization}
                        onChange={(e) =>
                            form.setData('authorization', e.target.value)
                        }
                        placeholder="Si existe: nota, acta o resolución…"
                    />
                    <InputError message={form.errors.authorization} />
                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        type="button"
                        onClick={cerrarDialogo}
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="button"
                        disabled={form.processing}
                        onClick={() =>
                            form.post(adjust({ cashCount: arqueo.id }).url, {
                                preserveScroll: true,
                                onSuccess: cerrarDialogo,
                            })
                        }
                    >
                        {form.processing && <Spinner />}
                        {form.processing ? 'Imputando…' : 'Imputar'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
