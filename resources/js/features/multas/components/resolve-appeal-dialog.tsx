import { useForm } from '@inertiajs/react';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import FormDialog, {
    FieldError,
} from '@/features/multas/components/form-dialog';
import { money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { resolve } from '@/routes/multas/recursos';

type Veredicto = 'upheld' | 'rejected';

/**
 * El veredicto del recurso (decisión del área, 03/11/2026).
 *
 * Se abre con el 20 % ya cobrado o verificado. Prosperar cancela la multa con
 * lo cobrado según el recibo externo, sin devolver nada. No prosperar la
 * descongela y lo pagado queda a cuenta. No pide resolución: si llega una, se
 * carga aparte en la multa.
 */
export default function ResolveAppealDialog({
    fineId,
    appealId,
    paymentId,
    paidAmount,
    onClose,
}: {
    fineId: number;
    /** El recurso y el pago que se ven: si cambiaron, el servidor lo rechaza. */
    appealId: number;
    paymentId: number;
    /** Lo que se cobró por el recurso, según el recibo externo. */
    paidAmount: string | null;
    onClose: () => void;
}) {
    // Sin preselección: el veredicto se elige a propósito.
    const { data, setData, post, processing, errors } = useForm<{
        verdict: Veredicto | '';
        appealId: number;
        paymentId: number;
    }>({ verdict: '', appealId, paymentId });
    const cobrado = paidAmount === null ? 'lo cobrado' : money(paidAmount);

    const opciones: {
        valor: Veredicto;
        titulo: string;
        explicacion: string;
    }[] = [
        {
            valor: 'upheld',
            titulo: 'Prosperó',
            explicacion: `La multa queda cancelada con ${cobrado}. No se devuelve nada y su importe ya no se modifica.`,
        },
        {
            valor: 'rejected',
            titulo: 'No prosperó',
            explicacion: `La multa vuelve a admitir pagos: ${cobrado} queda a cuenta y se cobra el resto por cualquier medio.`,
        },
    ];

    return (
        <FormDialog
            title="Resolver recurso"
            description="El veredicto queda registrado con quién lo dio. Si se carga mal, se revierte con su motivo."
            onClose={onClose}
            onSubmit={() =>
                post(resolve(fineId).url, {
                    preserveScroll: true,
                    onSuccess: onClose,
                })
            }
            processing={processing}
            submitLabel="Resolver"
        >
            <RadioGroup
                value={data.verdict}
                onValueChange={(valor) =>
                    setData('verdict', valor as Veredicto)
                }
                aria-label="Veredicto"
            >
                {opciones.map((opcion) => {
                    const id = `veredicto-${opcion.valor}`;

                    return (
                        <label
                            key={opcion.valor}
                            htmlFor={id}
                            className={cn(
                                'flex cursor-pointer items-start gap-3 rounded-lg border p-4 transition-colors hover:bg-muted/40',
                                data.verdict === opcion.valor &&
                                    'border-primary bg-primary/5 hover:bg-primary/5',
                            )}
                        >
                            <RadioGroupItem
                                id={id}
                                value={opcion.valor}
                                aria-describedby={`${id}-explicacion`}
                                className="mt-0.5"
                            />
                            <span className="grid gap-1">
                                <span className="text-sm font-medium">
                                    {opcion.titulo}
                                </span>
                                <span
                                    id={`${id}-explicacion`}
                                    className="text-xs text-muted-foreground"
                                >
                                    {opcion.explicacion}
                                </span>
                            </span>
                        </label>
                    );
                })}
            </RadioGroup>
            <FieldError message={errors.verdict} />
        </FormDialog>
    );
}
