import { useForm, usePage } from '@inertiajs/react';
import { Ban } from 'lucide-react';
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
import { voidMethod as anularOrden } from '@/routes/ordenes';

type Orden = App.Modules.Haberes.Data.PaymentOrderSummaryData;

/**
 * Anular la Orden y su Pase.
 *
 * **Es el camino de la Orden devuelta.** El área lo confirmó: *«la orden
 * de pago devuelta queda anulada; se genera una nueva orden de pago»*. El
 * otro camino —agregar una foja aclaratoria y dejar que los mismos papeles
 * sigan— está en «Corregir observaciones», y cuál corresponde lo decide
 * quien opera: es el único que tiene el documento devuelto a la vista.
 *
 * Anular es además la **única** salida cuando lo que está mal es **el
 * dinero o las partes**. Eso no se edita —la base lo impide— porque el
 * papel puede estar en manos del organismo, y corregir el registro por
 * detrás lo dejaría diciendo algo distinto del documento que circula.
 *
 * Los dos documentos caen juntos, que es como el área los emitió. Después
 * se genera una Orden nueva, encadenada a esta, con el siguiente número
 * libre de la serie: el de la anulada queda consumido, como en un
 * talonario.
 */
export default function VoidOrderDialog({
    orden,
    abierto,
    onCerrar,
}: {
    orden: Orden;
    abierto: boolean;
    onCerrar: () => void;
}) {
    const form = useForm({ reason: '' });

    const { errors } = usePage().props as unknown as {
        errors?: Record<string, string>;
    };

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();

        form.post(anularOrden(orden.id).url, {
            preserveScroll: true,
            onSuccess: () => onCerrar(),
        });
    };

    return (
        <Dialog open={abierto} onOpenChange={(v) => !v && onCerrar()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        Anular la Orden de Pago {orden.number}
                    </DialogTitle>
                    <DialogDescription>
                        Para cuando el organismo la devolvió, o cuando está mal
                        el dinero o las partes. Si lo que falta es solo una
                        aclaración, el otro camino es agregar la foja y corregir
                        las observaciones, sin rehacer nada.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={enviar} className="mt-4 space-y-4">
                    <div className="space-y-2 rounded-lg border border-destructive/40 bg-destructive-soft p-3 text-xs">
                        <p className="font-medium">Al confirmar:</p>
                        <ul className="ml-4 list-disc space-y-1 text-muted-foreground">
                            <li>
                                La Orden{' '}
                                <strong className="font-mono">
                                    {orden.number}
                                </strong>{' '}
                                queda anulada y su número, consumido.
                            </li>
                            {orden.pase !== null && (
                                <li>
                                    Su Pase a {orden.pase.destination} queda
                                    anulado con ella: los dos viajan juntos.
                                </li>
                            )}
                            <li>
                                El recibo de ingreso y el dinero recibido no se
                                tocan: lo que se deshace es el pedido de pago,
                                no el ingreso que lo respalda.
                            </li>
                        </ul>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor={`motivo-orden-${orden.id}`}>
                            Por qué se anula
                        </Label>
                        <Textarea
                            id={`motivo-orden-${orden.id}`}
                            rows={3}
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                            placeholder="El CBU impreso es el de otra cuenta del trabajador; el expediente informa el correcto a fs. 19."
                        />
                        <InputError message={form.errors.reason} />
                    </div>

                    <InputError message={errors?.orderId} />

                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onCerrar}
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={form.processing}
                        >
                            <Ban className="size-4" />
                            Anular la Orden y su Pase
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
