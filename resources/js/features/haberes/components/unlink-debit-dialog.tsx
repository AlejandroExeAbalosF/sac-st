import { useForm } from '@inertiajs/react';
import { Undo2 } from 'lucide-react';
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
import { unlinkDebit as desvincular } from '@/routes/haberes/installments/disbursement';

/**
 * Deshacer el reconocimiento del débito, con su motivo.
 *
 * Libera la transferencia y su comisión para otra Orden y devuelve el
 * egreso a esperar su débito. Pide confirmar y el motivo de la lista, como
 * los demás actos que deshacen algo: un clic no alcanza para desarmar lo
 * que otro reconoció.
 */
export default function UnlinkDebitDialog({
    cuotaId,
    conComision,
    onClose,
}: {
    cuotaId: number;
    conComision: boolean;
    onClose: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        reasonCode: '' as App.Modules.Haberes.Enums.HaberesReasonCode | '',
        reason: '',
    });

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();
        post(desvincular(cuotaId).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Desvincular el débito</DialogTitle>
                    <DialogDescription>
                        {conComision
                            ? 'La transferencia y su comisión quedan libres para otra Orden, y el egreso vuelve a esperar su débito.'
                            : 'El débito queda libre para otra Orden, y el egreso vuelve a esperar su débito.'}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={enviar} className="grid gap-4">
                    <ReasonFields
                        act="debit_unlink"
                        code={data.reasonCode}
                        detail={data.reason}
                        onCodeChange={(codigo) => setData('reasonCode', codigo)}
                        onDetailChange={(detalle) => setData('reason', detalle)}
                        codeError={errors.reasonCode}
                        detailError={errors.reason}
                        idPrefix={`desvincular-${cuotaId}`}
                        maxLength={300}
                    />

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
                            <Undo2 className="size-4" />
                            Desvincular
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
