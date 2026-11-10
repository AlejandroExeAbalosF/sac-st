import { useForm } from '@inertiajs/react';
import { BadgePlus } from 'lucide-react';
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
    compareAmounts,
    isNonZero,
    money,
    parseAmount,
    subtractAmounts,
} from '@/lib/format';
import { recordExcess as registrar } from '@/routes/haberes/installments';

type Cuota = App.Modules.Haberes.Data.InstallmentListItemData;
type Motivo = App.Modules.Haberes.Enums.HaberesReasonCode | '';

/**
 * Registrar que por la cuota entra más de lo que se debe.
 *
 * Se escribe el total —lo que trae el empleador o dice el comprobante— y el
 * sistema calcula el excedente contra lo que se debe. No mueve dinero: la
 * cuota pasa a esperar ese total, y el cobro o el cruce lo toman de ahí.
 *
 * Con un comprobante a la espera, el total es el suyo y ya viene escrito: el
 * cruce con el banco exige igualdad exacta. Escribir lo que se debe en una
 * cuota con excedente lo quita, con su propio motivo.
 */
export default function InstallmentExcessDialog({
    cuota,
    abierto,
    onCerrar,
}: {
    cuota: Cuota;
    abierto: boolean;
    onCerrar: () => void;
}) {
    /*
     * Lo que suman los comprobantes a la espera: con un depósito fraccionado
     * son varios, y el servidor exige que el total coincida con todos.
     */
    const comprobantes = cuota.waitingTicketsAmount;
    const tieneExcedente = isNonZero(cuota.excessAmount);

    const form = useForm({
        amount: comprobantes ?? (tieneExcedente ? cuota.expectedAmount : ''),
        version: cuota.updatedAt,
        remove: false,
        reasonCode: '' as Motivo,
        reason: '',
    });

    const total = parseAmount(form.data.amount);
    const excedente =
        total === '' ? null : subtractAmounts(total, cuota.amountDue);
    const quita =
        tieneExcedente &&
        total !== '' &&
        compareAmounts(total, cuota.amountDue) === 0;

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();

        form.transform((datos) => ({ ...datos, remove: quita }));
        form.post(registrar(cuota.id).url, {
            preserveScroll: true,
            onSuccess: () => onCerrar(),
        });
    };

    return (
        <Dialog open={abierto} onOpenChange={(v) => !v && onCerrar()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        Registrar importe mayor · cuota {cuota.number}
                    </DialogTitle>
                    <DialogDescription>
                        {quita
                            ? 'La cuota vuelve a esperar lo que se debe.'
                            : 'La cuota pasa a esperar el total, y la diferencia queda como excedente recibido.'}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={enviar} className="mt-4 space-y-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor={`importe-mayor-${cuota.id}`}>
                            {comprobantes !== null
                                ? 'Importe del comprobante'
                                : 'Total a cobrar'}
                        </Label>
                        <AmountInput
                            id={`importe-mayor-${cuota.id}`}
                            value={form.data.amount}
                            onChange={(valor) => form.setData('amount', valor)}
                            readOnly={comprobantes !== null}
                            words="below"
                            required
                        />
                        <InputError message={form.errors.amount} />
                    </div>

                    <dl className="grid grid-cols-2 gap-3 rounded-lg border bg-muted/30 p-3 text-sm">
                        <div>
                            <dt className="text-xs text-field-label">
                                Importe a abonar
                            </dt>
                            <dd className="tabular-nums">
                                {money(cuota.amountDue)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-field-label">
                                Excedente recibido
                            </dt>
                            <dd className="tabular-nums">
                                {excedente === null ||
                                compareAmounts(excedente, '0') === -1
                                    ? '—'
                                    : money(excedente)}
                            </dd>
                        </div>
                    </dl>

                    <ReasonFields
                        act={
                            quita
                                ? 'installment_excess_removal'
                                : 'installment_excess'
                        }
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
                        idPrefix={`motivo-importe-mayor-${cuota.id}`}
                        maxLength={300}
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
                            <BadgePlus className="size-4" aria-hidden="true" />
                            {quita ? 'Quitar excedente' : 'Registrar'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
