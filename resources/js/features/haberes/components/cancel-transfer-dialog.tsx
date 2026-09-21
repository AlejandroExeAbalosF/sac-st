import { useForm, usePage } from '@inertiajs/react';
import { Undo2 } from 'lucide-react';
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
import { cancel as cancelarTraslado } from '@/routes/traslados';

type Traslado = App.Modules.Haberes.Data.InstallmentTransferData;

/**
 * Deshacer un traslado que no ocurrió.
 *
 * Para cuando el depósito se cargó por error — la fecha, el importe, o
 * directamente el traslado equivocado. El efectivo vuelve a figurar en la
 * caja, que es donde estuvo todo el tiempo: lo que se deshace es la
 * afirmación de que salió.
 *
 * **Sólo lo que sigue en tránsito.** Un traslado acreditado está
 * confirmado por el extracto y ese dinero está en la cuenta.
 */
export default function CancelTransferDialog({
    traslado,
    abierto,
    onCerrar,
}: {
    traslado: Traslado;
    abierto: boolean;
    onCerrar: () => void;
}) {
    const [clave] = useState(
        () => `cancelar-traslado:${traslado.id}:${crypto.randomUUID()}`,
    );

    const form = useForm({ reason: '', idempotencyKey: clave });

    const { errors } = usePage().props as unknown as {
        errors?: Record<string, string>;
    };

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();

        form.post(cancelarTraslado(traslado.id).url, {
            preserveScroll: true,
            onSuccess: () => onCerrar(),
        });
    };

    return (
        <Dialog open={abierto} onOpenChange={(v) => !v && onCerrar()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Cancelar el traslado</DialogTitle>
                    <DialogDescription>
                        Para cuando el depósito no ocurrió o se cargó
                        equivocado. El efectivo vuelve a figurar en la caja.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={enviar} className="mt-4 space-y-4">
                    <dl className="space-y-1.5 rounded-lg border p-3 text-sm">
                        <div className="flex justify-between gap-3">
                            <dt className="text-xs text-field-label">
                                Depositado el
                            </dt>
                            <dd>{date(traslado.depositDate)}</dd>
                        </div>
                        <div className="flex justify-between gap-3">
                            <dt className="text-xs text-field-label">
                                Importe
                            </dt>
                            <dd className="font-mono tabular-nums">
                                {money(traslado.amount)}
                            </dd>
                        </div>
                    </dl>

                    <div className="space-y-1.5">
                        <Label htmlFor={`motivo-cancelar-${traslado.id}`}>
                            Por qué se cancela
                        </Label>
                        <Textarea
                            id={`motivo-cancelar-${traslado.id}`}
                            rows={3}
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                            placeholder="El depósito se cargó sobre la cuota equivocada."
                        />
                        <InputError message={form.errors.reason} />
                    </div>

                    <InputError message={errors?.transferId} />

                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onCerrar}
                        >
                            Volver
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={form.processing}
                        >
                            <Undo2 className="size-4" />
                            Cancelar el traslado
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
