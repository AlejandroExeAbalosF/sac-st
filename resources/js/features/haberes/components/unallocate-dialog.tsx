import { useForm } from '@inertiajs/react';
import { Undo2 } from 'lucide-react';
import { useState } from 'react';
import AmountInput from '@/components/amount-input';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { date, money, parseAmount } from '@/lib/format';
import { unallocate as desasignar } from '@/routes/haberes/installments';

type Cuota = App.Modules.Haberes.Data.InstallmentListItemData;

/**
 * Liberar plata imputada a esta cuota.
 *
 * **No es devolvérsela al empleador.** El dinero no se mueve de la caja ni
 * de la cuenta: deja de estar asignado a este beneficiario y vuelve al
 * pozo de no identificados, esperando a la cuota que corresponda. Decirle
 * «devolución» lo confundiría con §2.6, que sí saca plata del organismo.
 *
 * **Es un movimiento, no una corrección de pantalla**, y por eso el motivo
 * es obligatorio: alguien tiene que poder leer el año que viene por qué
 * ese dinero dejó de tener dueño.
 *
 * Se propone el excedente, que es el caso habitual —el importe de la cuota
 * se corrigió y quedó plata de más—, pero se puede liberar todo: es lo que
 * hace falta cuando la recepción se imputó a la cuota equivocada.
 */
export default function UnallocateDialog({
    cuota,
    abierto,
    onCerrar,
}: {
    cuota: Cuota;
    abierto: boolean;
    onCerrar: () => void;
}) {
    const [clave] = useState(
        () => `desasignar:${cuota.id}:${crypto.randomUUID()}`,
    );

    const form = useForm({
        /*
         * De cuál de las imputaciones sale la plata. Con una sola —el caso
         * habitual— no hay nada que elegir; con varias hay que decirlo,
         * porque devolver de una o de otra deja libre un crédito bancario
         * distinto.
         */
        allocationId: cuota.allocations[0]?.id ?? 0,
        amount: money(cuota.overAllocatedAmount, { symbol: false }),
        reason: '',
        idempotencyKey: clave,
    });

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();

        form.transform((datos) => ({
            ...datos,
            amount: parseAmount(datos.amount),
        }));

        form.post(desasignar(cuota.id).url, {
            preserveScroll: true,
            onSuccess: () => onCerrar(),
        });
    };

    return (
        <Dialog open={abierto} onOpenChange={(v) => !v && onCerrar()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Liberar dinero de esta cuota</DialogTitle>
                    <DialogDescription>
                        El dinero se queda en el organismo: deja de estar
                        imputado a este beneficiario y vuelve a la cola de
                        fondos sin identificar, para que lo tome la cuota que
                        corresponda. El asiento se registra con la fecha de hoy.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={enviar} className="mt-4 space-y-4">
                    <dl className="space-y-1.5 rounded-lg border p-3 text-sm">
                        <div className="flex justify-between gap-3">
                            <dt className="text-xs text-field-label">
                                La cuota espera
                            </dt>
                            <dd className="font-mono tabular-nums">
                                {money(cuota.expectedAmount)}
                            </dd>
                        </div>
                        <div className="flex justify-between gap-3">
                            <dt className="text-xs text-field-label">
                                Tiene imputado
                            </dt>
                            <dd className="font-mono tabular-nums">
                                {money(cuota.fundedAmount)}
                            </dd>
                        </div>
                    </dl>

                    {cuota.allocations.length > 1 && (
                        <div className="space-y-1.5">
                            <Label htmlFor={`imputacion-${cuota.id}`}>
                                De qué imputación
                            </Label>
                            <Select
                                value={form.data.allocationId.toString()}
                                onValueChange={(v) =>
                                    form.setData('allocationId', Number(v))
                                }
                            >
                                <SelectTrigger id={`imputacion-${cuota.id}`}>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {cuota.allocations.map((a) => (
                                        <SelectItem
                                            key={a.id}
                                            value={a.id.toString()}
                                        >
                                            {money(a.amount)} ·{' '}
                                            {date(a.receivedDate)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.allocationId} />
                        </div>
                    )}

                    <div className="space-y-1.5">
                        <Label htmlFor={`devolver-${cuota.id}`}>
                            Importe a liberar
                        </Label>
                        <AmountInput
                            id={`devolver-${cuota.id}`}
                            value={form.data.amount}
                            onChange={(v) => form.setData('amount', v)}
                            words="below"
                            required
                        />
                        <InputError message={form.errors.amount} />
                    </div>

                    {/*
                     * El motivo es obligatorio y no es burocracia: es lo
                     * único que va a explicar el movimiento cuando nadie se
                     * acuerde de por qué se hizo.
                     */}
                    <div className="space-y-1.5">
                        <Label htmlFor={`motivo-${cuota.id}`}>
                            Por qué se libera
                        </Label>
                        <Textarea
                            id={`motivo-${cuota.id}`}
                            rows={3}
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                            placeholder="El importe de la cuota se corrigió; el excedente vuelve al pozo."
                        />
                        <InputError message={form.errors.reason} />
                    </div>

                    {cuota.incomeReceipt !== null && (
                        <p className="rounded-md border border-warning/40 bg-warning/5 px-3 py-2 text-xs">
                            Esta cuota tiene el recibo{' '}
                            <strong className="font-mono">
                                {cuota.incomeReceipt.formattedNumber}
                            </strong>{' '}
                            emitido por {money(cuota.incomeReceipt.amount)}. No
                            se toca: documenta lo que el empleador pagó, que
                            sigue siendo cierto. Después de liberar, la cuota va
                            a esperar menos que eso.
                        </p>
                    )}

                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onCerrar}
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            <Undo2 className="size-4" />
                            Liberar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
