import { useForm } from '@inertiajs/react';
import { Undo2 } from 'lucide-react';
import { useState } from 'react';
import AmountInput from '@/components/amount-input';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
    delSistemaAnterior = false,
}: {
    cuota: Cuota;
    abierto: boolean;
    onCerrar: () => void;
    /**
     * Si la plata se apartó del sistema anterior. Vuelve a ese saldo y no a
     * los fondos sin identificar, y se propone liberar la imputación
     * entera: es el caso de haberla apartado para la cuota equivocada, y un
     * cheque del sistema anterior solo se libera entero.
     */
    delSistemaAnterior?: boolean;
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
        amount: money(
            delSistemaAnterior
                ? (cuota.allocations[0]?.amount ?? '0')
                : cuota.overAllocatedAmount,
            { symbol: false },
        ),
        reasonCode: '' as App.Modules.Haberes.Enums.HaberesReasonCode | '',
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
                    <DialogTitle>
                        {delSistemaAnterior
                            ? 'Quitar la reserva de esta cuota'
                            : 'Liberar dinero de esta cuota'}
                    </DialogTitle>
                    <DialogDescription>
                        {delSistemaAnterior
                            ? 'El dinero vuelve al saldo del sistema anterior, con fecha de hoy. Un cheque se libera entero.'
                            : 'El dinero vuelve a los fondos sin identificar, con fecha de hoy.'}
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
                                onValueChange={(v) => {
                                    form.setData('allocationId', Number(v));

                                    // Del sistema anterior se propone la imputación entera.
                                    if (delSistemaAnterior) {
                                        form.setData(
                                            'amount',
                                            money(
                                                cuota.allocations.find(
                                                    (a) => a.id === Number(v),
                                                )?.amount ?? '0',
                                                { symbol: false },
                                            ),
                                        );
                                    }
                                }}
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

                    <ReasonFields
                        act="funds_release"
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
                        idPrefix={`motivo-liberar-${cuota.id}`}
                        maxLength={250}
                    />

                    {cuota.incomeReceipt !== null && (
                        <p className="rounded-md border border-warning/40 bg-warning/5 px-3 py-2 text-xs">
                            El recibo{' '}
                            <strong className="font-mono">
                                {cuota.incomeReceipt.formattedNumber}
                            </strong>{' '}
                            por {money(cuota.incomeReceipt.amount)} sigue
                            vigente.
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
