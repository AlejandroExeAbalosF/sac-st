import { useForm, usePage } from '@inertiajs/react';
import { Ban } from 'lucide-react';
import InputError from '@/components/input-error';
import ReasonFields from '@/components/reason-fields';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { voidMethod as anularOrden } from '@/routes/ordenes';

type Orden = App.Modules.Haberes.Data.PaymentOrderSummaryData;

/**
 * Anular la Orden y su Pase.
 *
 * **Es el camino de la Orden devuelta:** queda anulada y se genera una
 * nueva. El
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
    const form = useForm({
        reasonCode: '' as App.Modules.Haberes.Enums.HaberesReasonCode | '',
        reason: '',
    });

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
                        Su número queda consumido
                        {orden.pase !== null &&
                            ` y su Pase a ${orden.pase.destination} también se anula`}
                        . El recibo de ingreso y el dinero no cambian.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={enviar} className="mt-4 space-y-4">
                    <ReasonFields
                        act="payment_order_void"
                        code={form.data.reasonCode}
                        detail={form.data.reason}
                        onCodeChange={(codigo) =>
                            form.setData('reasonCode', codigo)
                        }
                        onDetailChange={(detalle) =>
                            form.setData('reason', detalle)
                        }
                        codeError={form.errors.reasonCode}
                        detailError={form.errors.reason}
                        idPrefix={`motivo-orden-${orden.id}`}
                        maxLength={300}
                    />

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
