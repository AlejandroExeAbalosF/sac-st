import { useForm } from '@inertiajs/react';
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
import {
    cancel as cancelHaber,
    reactivate as reactivateHaber,
} from '@/routes/haberes/haber';

type Haber = App.Modules.Haberes.Data.HaberListItemData;

/**
 * Anulación de un haber y su reverso.
 *
 * Un solo diálogo para las dos direcciones: piden lo mismo, un motivo que
 * explique qué pasó, y difieren en el texto y en la ruta.
 */
export default function HaberCancelDialog({
    haber,
    reactivar: esReactivacion,
    onClose,
}: {
    haber: Haber;
    reactivar: boolean;
    onClose: () => void;
}) {
    const { data, setData, patch, processing, errors } = useForm({
        reason: '',
    });

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();
        patch(
            esReactivacion
                ? reactivateHaber([haber.expedienteId, haber.haberNumber]).url
                : cancelHaber([haber.expedienteId, haber.haberNumber]).url,
            { preserveScroll: true, onSuccess: onClose },
        );
    };

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {esReactivacion ? 'Reactivar' : 'Anular'} el haber de{' '}
                        {haber.beneficiaryName}
                    </DialogTitle>
                    <DialogDescription>
                        {esReactivacion
                            ? 'Sus cuotas vuelven al estado que tenían antes, y el importe vuelve a contar en lo reconocido.'
                            : 'No se borra: queda registrado como anulado, sus cuotas pasan al mismo estado y el importe deja de contar en lo reconocido del expediente.'}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={enviar} className="grid gap-4">
                    <div>
                        <Label htmlFor={`motivo-${haber.id}`}>Motivo</Label>
                        <Textarea
                            id={`motivo-${haber.id}`}
                            value={data.reason}
                            onChange={(e) => setData('reason', e.target.value)}
                            className="mt-1"
                            rows={3}
                            maxLength={500}
                            autoFocus
                            aria-invalid={Boolean(errors.reason)}
                            placeholder={
                                esReactivacion
                                    ? 'Por qué vuelve a estar vigente.'
                                    : 'Qué pasó.'
                            }
                        />
                        {errors.reason && (
                            <p className="mt-1 text-xs text-destructive-strong">
                                {errors.reason}
                            </p>
                        )}
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={onClose}
                            disabled={processing}
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            variant={esReactivacion ? 'default' : 'destructive'}
                            disabled={processing}
                        >
                            {processing
                                ? 'Guardando…'
                                : esReactivacion
                                  ? 'Reactivar haber'
                                  : 'Anular haber'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
