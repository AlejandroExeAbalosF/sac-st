import { useForm } from '@inertiajs/react';
import { CircleX } from 'lucide-react';
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
import { fail as darPorFallida } from '@/routes/haberes/installments/disbursement';

/**
 * Dar por fallida la transferencia en curso, con su motivo.
 *
 * Manda el id del egreso que se está mirando: si mientras tanto otro
 * operador lo cerró y nació otro, el servidor rechaza el pedido en vez de
 * cerrar el nuevo.
 */
export default function FailTransferDialog({
    cuotaId,
    egresoId,
    onClose,
}: {
    cuotaId: number;
    egresoId: number;
    onClose: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        disbursementId: egresoId,
        reasonCode: '' as App.Modules.Haberes.Enums.HaberesReasonCode | '',
        reason: '',
    });

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();
        post(darPorFallida(cuotaId).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Dar por fallida la transferencia</DialogTitle>
                    <DialogDescription>
                        La transferencia queda cerrada sin pago y la Orden se
                        puede anular para emitir otra.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={enviar} className="grid gap-4">
                    <ReasonFields
                        act="transfer_failure"
                        code={data.reasonCode}
                        detail={data.reason}
                        onCodeChange={(codigo) => setData('reasonCode', codigo)}
                        onDetailChange={(detalle) => setData('reason', detalle)}
                        codeError={errors.reasonCode}
                        detailError={errors.reason}
                        idPrefix={`fallida-${cuotaId}`}
                        maxLength={300}
                    />

                    {errors.disbursementId !== undefined && (
                        <p className="text-sm text-destructive">
                            {errors.disbursementId}
                        </p>
                    )}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={processing}
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={processing}
                        >
                            <CircleX className="size-4" />
                            Dar por fallida
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
