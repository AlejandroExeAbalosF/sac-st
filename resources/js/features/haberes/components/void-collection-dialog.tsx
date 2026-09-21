import { useForm, usePage } from '@inertiajs/react';
import { Ban } from 'lucide-react';
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
import { money } from '@/lib/format';
import { voidCollection as anularCobro } from '@/routes/haberes/installments';

type Cuota = App.Modules.Haberes.Data.InstallmentListItemData;

/**
 * Anular el cobro: el dinero nunca entró.
 *
 * **No es liberar.** Liberar mueve plata que existe de una cuota al pozo
 * de no identificados; esto deshace la afirmación de que entró. Se usa
 * cuando el importe se tipeó mal y el sistema asentó en la caja un dinero
 * que nadie trajo.
 *
 * Deja la cuota sin cobrar y el recibo anulado con su número. Después el
 * circuito normal la cobra bien y emite uno nuevo que apunta al anulado.
 */
export default function VoidCollectionDialog({
    cuota,
    abierto,
    onCerrar,
}: {
    cuota: Cuota;
    abierto: boolean;
    onCerrar: () => void;
}) {
    const [clave] = useState(
        () => `anular-cobro:${cuota.id}:${crypto.randomUUID()}`,
    );

    const form = useForm({
        reason: '',
        idempotencyKey: clave,
    });

    const { errors } = usePage().props as unknown as {
        errors?: Record<string, string>;
    };

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();

        form.post(anularCobro(cuota.id).url, {
            preserveScroll: true,
            onSuccess: () => onCerrar(),
        });
    };

    return (
        <Dialog open={abierto} onOpenChange={(v) => !v && onCerrar()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Anular el recibo de ingreso</DialogTitle>
                    <DialogDescription>
                        Para cuando el dinero nunca entró — un importe mal
                        tipeado, por ejemplo. Si el dinero sí entró y solo está
                        en la cuota equivocada, lo que corresponde es liberarlo,
                        no anular.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={enviar} className="mt-4 space-y-4">
                    <div className="space-y-2 rounded-lg border border-destructive/40 bg-destructive-soft p-3 text-xs">
                        <p className="font-medium">Al confirmar:</p>
                        <ul className="ml-4 list-disc space-y-1 text-muted-foreground">
                            <li>
                                Salen {money(cuota.fundedAmount)} de la caja: el
                                libro deja de afirmar que ese dinero está.
                            </li>
                            <li>La cuota vuelve a quedar sin cobrar.</li>
                            {cuota.incomeReceipt !== null && (
                                <li>
                                    El recibo{' '}
                                    <strong className="font-mono">
                                        {cuota.incomeReceipt.formattedNumber}
                                    </strong>{' '}
                                    queda anulado, con su número. El que se
                                    emita después toma el siguiente libre de la
                                    serie y lo referencia.
                                </li>
                            )}
                        </ul>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor={`motivo-anular-${cuota.id}`}>
                            Por qué se anula
                        </Label>
                        <Textarea
                            id={`motivo-anular-${cuota.id}`}
                            rows={3}
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                            placeholder="El importe se cargó con un cero de más; el empleador trajo $ 100.000."
                        />
                        <InputError message={form.errors.reason} />
                    </div>

                    <InputError message={errors?.installmentId} />

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
                            Anular el recibo
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
