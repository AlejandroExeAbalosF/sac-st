import { useForm } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
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
import { unlockEdit as habilitar } from '@/routes/haberes/installments';

/**
 * Registrar el caso para poder corregir una cuota en circulación.
 *
 * **No pide un permiso: pide una explicación.** Quien abre esto ya podía
 * editar la cuota; lo que cambió es que su Orden y su Pase salieron del
 * área, y ahora hay alguien afuera leyendo lo que ese dato dice.
 *
 * El área definió el procedimiento en tres pasos y este es el primero. El
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
    const form = useForm({ reason: '' });

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

                    <div className="space-y-1.5">
                        <Label htmlFor={`motivo-editar-${cuotaId}`}>
                            Qué pasó, y por qué hay que corregir
                        </Label>
                        <Textarea
                            id={`motivo-editar-${cuotaId}`}
                            rows={3}
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                            placeholder="El expediente volvió del SAF observado: el concepto de la cuota no coincide con el de la resolución a fs. 24."
                        />
                        <InputError message={form.errors.reason} />
                    </div>

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
