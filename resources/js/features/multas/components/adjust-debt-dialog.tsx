import { useForm } from '@inertiajs/react';
import AmountInput from '@/components/amount-input';
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
import FormDialog, {
    FieldError,
} from '@/features/multas/components/form-dialog';
import { businessToday, parseAmount } from '@/lib/format';
import { store } from '@/routes/multas/ajustes';

type Documento = App.Modules.Multas.Data.FineDocumentData;

/**
 * Un aumento o una reducción de la obligación (M04).
 *
 * Lo dispone una resolución: la que el expediente ya tiene o una nueva que
 * se carga acá mismo. El importe original no se toca; el ajuste es otra
 * fila con su motivo.
 */
export default function AdjustDebtDialog({
    fineId,
    resoluciones,
    onClose,
}: {
    fineId: number;
    resoluciones: Documento[];
    onClose: () => void;
}) {
    const { data, setData, post, transform, processing, errors } = useForm({
        kind: 'reduction' as 'increase' | 'reduction',
        amount: '',
        effectiveOn: businessToday(),
        documentId: null as number | null,
        document: { text: '', issuedOn: '' },
        reason: '',
    });

    const errores = errors as Record<string, string | undefined>;

    const enviar = () => {
        transform((datos) => ({
            kind: datos.kind,
            amount: parseAmount(datos.amount),
            effectiveOn: datos.effectiveOn,
            ...(datos.documentId !== null
                ? { documentId: datos.documentId }
                : { document: datos.document }),
            reason: datos.reason,
        }));

        post(store(fineId).url, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <FormDialog
            title="Ajustar la obligación"
            description="Cuando una resolución cambia el importe. Los pagos ya hechos no cambian: si la obligación queda por debajo de lo pagado, la diferencia es excedente."
            onClose={onClose}
            onSubmit={enviar}
            processing={processing}
            submitLabel="Registrar ajuste"
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <Label htmlFor="ajuste-tipo">Tipo</Label>
                    <Select
                        value={data.kind}
                        onValueChange={(valor) =>
                            setData('kind', valor as 'increase' | 'reduction')
                        }
                    >
                        <SelectTrigger id="ajuste-tipo" className="mt-1">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="reduction">Reducción</SelectItem>
                            <SelectItem value="increase">Aumento</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div>
                    <Label htmlFor="ajuste-importe">Importe</Label>
                    <div className="mt-1">
                        <AmountInput
                            id="ajuste-importe"
                            value={data.amount}
                            onChange={(valor) => setData('amount', valor)}
                            placeholder="0,00"
                            aria-invalid={Boolean(errors.amount)}
                        />
                    </div>
                    <FieldError message={errors.amount} />
                </div>
            </div>

            <div>
                <Label htmlFor="ajuste-resolucion">
                    Resolución que lo dispone
                </Label>
                <Select
                    value={
                        data.documentId === null
                            ? 'nueva'
                            : String(data.documentId)
                    }
                    onValueChange={(valor) =>
                        setData(
                            'documentId',
                            valor === 'nueva' ? null : Number(valor),
                        )
                    }
                >
                    <SelectTrigger id="ajuste-resolucion" className="mt-1">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="nueva">
                            Cargar una resolución nueva
                        </SelectItem>
                        {resoluciones.map((r) => (
                            <SelectItem key={r.id} value={String(r.id)}>
                                {r.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <FieldError message={errors.documentId} />
            </div>

            {data.documentId === null && (
                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <Label htmlFor="ajuste-doc">Resolución</Label>
                        <Input
                            id="ajuste-doc"
                            className="mt-1 font-mono"
                            value={data.document.text}
                            onChange={(e) =>
                                setData('document', {
                                    ...data.document,
                                    text: e.target.value,
                                })
                            }
                            placeholder="N°1711/25"
                            maxLength={120}
                            aria-invalid={Boolean(errores['document.text'])}
                        />
                        <FieldError message={errores['document.text']} />
                    </div>
                    <div>
                        <Label htmlFor="ajuste-doc-fecha">
                            Fecha de la resolución
                        </Label>
                        <Input
                            id="ajuste-doc-fecha"
                            type="date"
                            className="mt-1"
                            value={data.document.issuedOn}
                            onChange={(e) =>
                                setData('document', {
                                    ...data.document,
                                    issuedOn: e.target.value,
                                })
                            }
                            aria-invalid={Boolean(errores['document.issuedOn'])}
                        />
                        <FieldError message={errores['document.issuedOn']} />
                    </div>
                </div>
            )}

            <div>
                <Label htmlFor="ajuste-fecha">Fecha del ajuste</Label>
                <Input
                    id="ajuste-fecha"
                    type="date"
                    className="mt-1 max-w-48"
                    value={data.effectiveOn}
                    onChange={(e) => setData('effectiveOn', e.target.value)}
                    aria-invalid={Boolean(errors.effectiveOn)}
                />
                <FieldError message={errors.effectiveOn} />
            </div>

            <div>
                <Label htmlFor="ajuste-motivo">Motivo</Label>
                <Textarea
                    id="ajuste-motivo"
                    className="mt-1"
                    rows={2}
                    maxLength={500}
                    value={data.reason}
                    onChange={(e) => setData('reason', e.target.value)}
                    placeholder="Qué dispone la resolución."
                    aria-invalid={Boolean(errors.reason)}
                />
                <FieldError message={errors.reason} />
            </div>
        </FormDialog>
    );
}
