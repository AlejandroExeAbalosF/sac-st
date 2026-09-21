import { useForm, usePage } from '@inertiajs/react';
import { CircleCheck } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { date, money } from '@/lib/format';
import { validate as validar } from '@/routes/haberes/installments/disbursement';

type Cuota = App.Modules.Haberes.Data.InstallmentListItemData;
type Estado = App.Modules.Haberes.Data.InstallmentDisbursementData;

/**
 * El cotejo del contador — §2.3.4.
 *
 * **Es el acto que convierte dos papeles en un pago.** Hasta acá hay un
 * aviso del organismo y una línea del extracto, y nadie que haya afirmado
 * que son el mismo hecho. Al confirmar se postea el asiento, la cuota
 * queda pagada y el recibo de egreso se habilita —las tres cosas juntas,
 * en ese orden, como lo pide el §2.3.5—.
 *
 * Por eso el diálogo muestra los tres datos enfrentados en vez de pedir un
 * clic a ciegas: lo que se está por firmar es que la Orden, el informe y
 * el débito se corresponden.
 */
export default function ValidateTransferDialog({
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
    const egreso = estado.disbursement;

    const form = useForm({ notes: '' });
    const [enviado, setEnviado] = useState(false);

    const { errors } = usePage().props as unknown as {
        errors?: Record<string, string>;
    };
    const erroresDelServidor = errors?.installmentId;

    const confirmar = (e: React.FormEvent) => {
        e.preventDefault();
        setEnviado(true);

        form.post(validar(cuota.id).url, {
            preserveScroll: true,
            onSuccess: () => onCerrar(),
        });
    };

    return (
        <Dialog open={abierto} onOpenChange={(v) => !v && onCerrar()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Validar el pago</DialogTitle>
                    <DialogDescription>
                        Al confirmar se asienta el egreso, la cuota queda pagada
                        y se habilita el recibo. Es lo que da el pago por hecho.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={confirmar} className="mt-2 space-y-4">
                    {/*
                     * Los tres papeles enfrentados. Cotejar es exactamente
                     * mirar que digan lo mismo, así que la pantalla los
                     * pone uno debajo del otro en vez de pedir un clic a
                     * ciegas.
                     */}
                    <dl className="space-y-2.5 rounded-lg border p-3 text-sm">
                        <Fila etiqueta="Orden de Pago">
                            <span className="font-mono">
                                {estado.orderNumber ?? '—'}
                            </span>
                        </Fila>
                        <Fila etiqueta="Importe">
                            <span className="font-mono font-semibold tabular-nums">
                                {money(estado.amount)}
                            </span>
                        </Fila>
                        <Fila etiqueta="Informó el organismo">
                            {egreso?.reportedAt == null
                                ? '—'
                                : date(egreso.reportedAt)}
                            {egreso?.transferReference != null && (
                                <span className="ml-2 font-mono text-xs text-muted-foreground">
                                    Ref. {egreso.transferReference}
                                </span>
                            )}
                        </Fila>
                        <Fila etiqueta="Débito en el extracto">
                            {egreso?.debitDate == null
                                ? '—'
                                : date(egreso.debitDate)}
                            {egreso?.debitOperationId != null && (
                                <span className="ml-2 font-mono text-xs text-muted-foreground">
                                    Op. {egreso.debitOperationId}
                                </span>
                            )}
                        </Fila>
                        {egreso?.beneficiaryCbu != null && (
                            <Fila etiqueta="CBU">
                                <span className="font-mono text-xs">
                                    {egreso.beneficiaryCbu}
                                </span>
                            </Fila>
                        )}
                    </dl>

                    <div className="space-y-1.5">
                        <Label htmlFor={`validar-notas-${cuota.id}`}>
                            Observaciones
                            <span className="ml-1 text-xs font-normal text-muted-foreground">
                                opcional
                            </span>
                        </Label>
                        <Textarea
                            id={`validar-notas-${cuota.id}`}
                            rows={2}
                            value={form.data.notes}
                            onChange={(e) =>
                                form.setData('notes', e.target.value)
                            }
                        />
                        <InputError message={form.errors.notes} />
                    </div>

                    {enviado && <InputError message={erroresDelServidor} />}

                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onCerrar}
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            <CircleCheck className="size-4" />
                            Se corresponden: validar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function Fila({
    etiqueta,
    children,
}: {
    etiqueta: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
            <dt className="text-xs text-field-label">{etiqueta}</dt>
            <dd>{children}</dd>
        </div>
    );
}
