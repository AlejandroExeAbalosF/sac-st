import { useForm } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
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
import { unlockEdit as habilitar } from '@/routes/haberes/installments';

/**
 * Registrar el caso para poder corregir una cuota en circulación.
 *
 * **No pide un permiso: pide una explicación.** Quien abre esto ya podía
 * editar la cuota; lo que cambió es que su Orden y su Pase salieron del
 * área, y ahora hay alguien afuera leyendo lo que ese dato dice.
 *
 * La corrección tiene tres pasos y este es el primero. El
 * tercero —volver a bloquear— no tiene botón: sucede solo al guardar los
 * cambios, porque una ventana que hay que acordarse de cerrar es un
 * permiso permanente con otro nombre.
 */
export default function UnlockEditDialog({
    cuotaId,
    numero,
    ordenNumero,
    abierto,
    onCerrar,
}: {
    cuotaId: number;
    numero: number;
    ordenNumero: number;
    abierto: boolean;
    onCerrar: () => void;
}) {
    const form = useForm({
        reasonCode: '' as App.Modules.Haberes.Enums.HaberesReasonCode | '',
        reason: '',
    });

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();

        form.post(habilitar(cuotaId).url, {
            preserveScroll: true,
            onSuccess: () => onCerrar(),
        });
    };

    return (
        <Dialog open={abierto} onOpenChange={(v) => !v && onCerrar()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        Habilitar la edición de la cuota {numero}
                    </DialogTitle>
                    <DialogDescription>
                        La Orden {ordenNumero} y su Pase salieron del área: el
                        dato está en circulación. Se puede corregir, pero el
                        caso queda registrado.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={enviar} className="mt-4 space-y-4">
                    <div className="rounded-lg border bg-muted/30 p-3 text-xs text-muted-foreground">
                        Al guardar los cambios, la cuota vuelve a quedar
                        bloqueada. El motivo queda junto al antes y el después
                        en el historial.
                    </div>

                    <ReasonFields
                        act="installment_unlock"
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
                        idPrefix={`motivo-editar-${cuotaId}`}
                        maxLength={300}
                        detailPlaceholder="El expediente volvió del SAF observado: el concepto de la cuota no coincide con el de la resolución a fs. 24."
                    />

                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onCerrar}
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            <KeyRound className="size-4" />
                            Registrar y habilitar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
