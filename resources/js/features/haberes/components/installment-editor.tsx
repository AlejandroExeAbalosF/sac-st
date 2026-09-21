import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import AmountInput from '@/components/amount-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import type { EtiquetaOption } from '@/features/haberes/types';
import { money, parseAmount } from '@/lib/format';
import installments from '@/routes/haberes/installments';

type Cuota = App.Modules.Haberes.Data.InstallmentListItemData;
type Medio = App.Modules.Haberes.Enums.ExpectedMedium;

type FormData = {
    amount: string;
    managementLabelId: number | null;
    concept: string;
    dueDate: string;
    expectedMedium: Medio | null;
    notes: string;
    returnTo: 'expediente' | 'haber';
    version: string | null;
};

type Props = {
    expedienteId: number;
    /** El ordinal del haber: es lo que va en la dirección. */
    haberNumber: number;
    numero: number;
    etiquetas: EtiquetaOption[];
    cuota?: Cuota;
    importeSugerido?: string;
    completaPlan: boolean;
    returnTo: 'expediente' | 'haber';
    onClose: () => void;
};

const MEDIOS: { value: Medio; label: string }[] = [
    { value: 'cash', label: 'Efectivo' },
    { value: 'bank', label: 'Depósito en cuenta' },
    { value: 'cheque', label: 'Cheque' },
];

function ErrorMessage({ id, message }: { id: string; message?: string }) {
    return message ? (
        <p id={id} className="mt-1 text-xs text-destructive-strong">
            {message}
        </p>
    ) : null;
}

/** Alta y corrección contextual de una cuota, sin sacar al operador del expediente. */
export default function InstallmentEditor({
    expedienteId,
    haberNumber,
    numero,
    etiquetas,
    cuota,
    importeSugerido = '',
    completaPlan,
    returnTo,
    onClose,
}: Props) {
    const editando = cuota !== undefined;

    const form = useForm<FormData>({
        amount: cuota
            ? money(cuota.expectedAmount, { symbol: false })
            : importeSugerido,
        managementLabelId: cuota?.managementLabelId ?? null,
        concept: cuota?.ownConcept ?? '',
        dueDate: cuota?.dueDate ?? '',
        expectedMedium: cuota?.expectedMedium ?? null,
        notes: cuota?.notes ?? '',
        returnTo,
        version: cuota?.updatedAt ?? null,
    });
    const formError = (form.errors as typeof form.errors & { form?: string })
        .form;

    const enviar = (event: React.FormEvent) => {
        event.preventDefault();

        form.transform((data) => ({
            ...data,
            amount: parseAmount(data.amount),
        }));

        const options = {
            preserveScroll: true,
            onSuccess: onClose,
        };

        if (cuota) {
            form.patch(
                installments.update({
                    expediente: expedienteId,
                    haber: haberNumber,
                    installment: cuota.id,
                }).url,
                options,
            );

            return;
        }

        form.post(
            installments.store({
                expediente: expedienteId,
                haber: haberNumber,
            }).url,
            options,
        );
    };

    return (
        <form
            onSubmit={enviar}
            aria-busy={form.processing}
            // Fondo sólido y sombra: con el resto atenuado, el editor tiene que
            // leerse por encima y no como una fila más de la lista.
            className="animate-in rounded-lg border-2 border-primary/40 bg-card p-4 shadow-raised duration-200 fade-in-0 slide-in-from-top-1 motion-reduce:animate-none sm:p-5"
        >
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h5 className="text-sm font-semibold text-primary">
                        {editando
                            ? `Editar cuota ${numero}`
                            : `Nueva cuota ${numero}`}
                    </h5>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {editando
                            ? 'Corregí únicamente los datos que hayan cambiado.'
                            : completaPlan
                              ? 'Es la última prevista: su importe debe completar el total reconocido.'
                              : 'Podés completar ahora solo la información que ya esté confirmada.'}
                    </p>
                </div>
                <span className="rounded-full bg-muted px-2 py-1 font-mono text-[0.6875rem] text-muted-foreground tabular-nums">
                    {numero}ª cuota
                </span>
            </div>

            {formError && (
                <p
                    role="alert"
                    className="mt-3 rounded-md border border-destructive/30 bg-destructive-soft px-3 py-2 text-xs text-destructive-strong"
                >
                    {formError}
                </p>
            )}

            <div className="mt-4 grid gap-x-5 gap-y-4 sm:grid-cols-12">
                <div className="sm:col-span-6 lg:col-span-3">
                    <Label htmlFor={`cuota-${numero}-amount`}>Importe</Label>
                    <div className="mt-1">
                        <AmountInput
                            id={`cuota-${numero}-amount`}
                            value={form.data.amount}
                            onChange={(amount) =>
                                form.setData('amount', amount)
                            }
                            words="below"
                            placeholder="0,00"
                            required
                            aria-invalid={Boolean(form.errors.amount)}
                            aria-describedby={
                                form.errors.amount
                                    ? `cuota-${numero}-amount-error`
                                    : undefined
                            }
                        />
                    </div>
                    <ErrorMessage
                        id={`cuota-${numero}-amount-error`}
                        message={form.errors.amount}
                    />
                </div>

                <div className="sm:col-span-6 lg:col-span-3">
                    {/* Sin marca de obligatorio: acá los que se señalan
                        son los opcionales, como «Concepto propio». */}
                    <Label htmlFor={`cuota-${numero}-medium`}>
                        Medio previsto
                    </Label>
                    {/*
                     * Ya no hay «Sin definir». Una cuota sin medio no se
                     * puede leer —no dice si se cobra por mostrador o si se
                     * espera un depósito— y de eso depende todo el circuito
                     * que viene después. El servidor lo exige y la columna
                     * es NOT NULL; dejar la opción acá sería ofrecer algo
                     * que el guardado rechaza.
                     */}
                    <Select
                        value={form.data.expectedMedium ?? ''}
                        onValueChange={(value) =>
                            form.setData('expectedMedium', value as Medio)
                        }
                    >
                        <SelectTrigger
                            id={`cuota-${numero}-medium`}
                            className="mt-1 h-11 w-full bg-card sm:h-9"
                            aria-invalid={Boolean(form.errors.expectedMedium)}
                        >
                            <SelectValue placeholder="Elegí el medio" />
                        </SelectTrigger>
                        <SelectContent align="start">
                            {MEDIOS.map((medio) => (
                                <SelectItem
                                    key={medio.value}
                                    value={medio.value}
                                >
                                    {medio.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <ErrorMessage
                        id={`cuota-${numero}-medium-error`}
                        message={form.errors.expectedMedium}
                    />
                </div>

                <div className="sm:col-span-6 lg:col-span-3">
                    <Label htmlFor={`cuota-${numero}-label`}>
                        Etiqueta de gestión
                    </Label>
                    <Select
                        value={
                            form.data.managementLabelId === null
                                ? 'none'
                                : String(form.data.managementLabelId)
                        }
                        onValueChange={(value) =>
                            form.setData(
                                'managementLabelId',
                                value === 'none' ? null : Number(value),
                            )
                        }
                    >
                        <SelectTrigger
                            id={`cuota-${numero}-label`}
                            className="mt-1 h-11 w-full bg-card sm:h-9"
                            aria-invalid={Boolean(
                                form.errors.managementLabelId,
                            )}
                        >
                            <SelectValue placeholder="Sin etiqueta" />
                        </SelectTrigger>
                        <SelectContent align="start">
                            <SelectItem value="none">Sin etiqueta</SelectItem>
                            {etiquetas.map((etiqueta) => (
                                <SelectItem
                                    key={etiqueta.id}
                                    value={String(etiqueta.id)}
                                >
                                    {etiqueta.code} · {etiqueta.description}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <ErrorMessage
                        id={`cuota-${numero}-label-error`}
                        message={form.errors.managementLabelId}
                    />
                </div>

                <div className="sm:col-span-6 lg:col-span-3">
                    <Label htmlFor={`cuota-${numero}-due-date`}>
                        Vencimiento
                    </Label>
                    <Input
                        id={`cuota-${numero}-due-date`}
                        type="date"
                        className="mt-1 h-11 sm:h-9"
                        value={form.data.dueDate}
                        onChange={(event) =>
                            form.setData('dueDate', event.target.value)
                        }
                        aria-invalid={Boolean(form.errors.dueDate)}
                    />
                    <ErrorMessage
                        id={`cuota-${numero}-due-date-error`}
                        message={form.errors.dueDate}
                    />
                </div>

                <div className="sm:col-span-12 lg:col-span-7">
                    <Label htmlFor={`cuota-${numero}-concept`}>
                        Concepto propio
                        <span className="ml-1 font-normal text-muted-foreground">
                            — opcional
                        </span>
                    </Label>
                    <Input
                        id={`cuota-${numero}-concept`}
                        className="mt-1 h-11 sm:h-9"
                        value={form.data.concept}
                        onChange={(event) =>
                            form.setData('concept', event.target.value)
                        }
                        maxLength={255}
                        placeholder="Si queda vacío, hereda el concepto del haber"
                        aria-invalid={Boolean(form.errors.concept)}
                    />
                    <ErrorMessage
                        id={`cuota-${numero}-concept-error`}
                        message={form.errors.concept}
                    />
                </div>

                <div className="sm:col-span-12 lg:col-span-5">
                    <Label htmlFor={`cuota-${numero}-notes`}>
                        Observaciones
                        <span className="ml-1 font-normal text-muted-foreground">
                            — no salen en el comprobante
                        </span>
                    </Label>
                    <Textarea
                        id={`cuota-${numero}-notes`}
                        className="mt-1 min-h-20 bg-card"
                        value={form.data.notes}
                        onChange={(event) =>
                            form.setData('notes', event.target.value)
                        }
                        maxLength={1000}
                        aria-invalid={Boolean(form.errors.notes)}
                    />
                    <ErrorMessage
                        id={`cuota-${numero}-notes-error`}
                        message={form.errors.notes}
                    />
                </div>
            </div>

            {editando && (
                <p className="mt-3 text-xs text-muted-foreground">
                    Si al reducir el importe queda saldo pendiente, se
                    habilitará una cuota adicional para completarlo.
                </p>
            )}

            <div className="mt-4 flex flex-col-reverse gap-2 border-t pt-4 sm:flex-row sm:justify-end">
                <Button
                    type="button"
                    variant="outline"
                    onClick={onClose}
                    disabled={form.processing}
                    className="h-11 sm:h-9"
                >
                    Cancelar
                </Button>
                <Button
                    type="submit"
                    disabled={form.processing}
                    className="h-11 sm:h-9"
                >
                    {form.processing && (
                        <LoaderCircle
                            className="animate-spin motion-reduce:animate-none"
                            aria-hidden="true"
                        />
                    )}
                    {form.processing
                        ? 'Guardando…'
                        : editando
                          ? 'Guardar cambios'
                          : 'Agregar cuota'}
                </Button>
            </div>
        </form>
    );
}
