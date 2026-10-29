import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import AmountInput from '@/components/amount-input';
import DateInput from '@/components/date-input';
import ListViewTab from '@/components/list-view-tab';
import Money from '@/components/money';
import ReasonFields from '@/components/reason-fields';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import FormDialog, {
    FieldError,
} from '@/features/multas/components/form-dialog';
import {
    businessToday,
    compareAmounts,
    parseAmount,
    subtractAmounts,
} from '@/lib/format';
import { store } from '@/routes/multas/ajustes';
import { store as corregir } from '@/routes/multas/correcciones-de-importe';

type Documento = App.Modules.Multas.Data.FineDocumentData;
type Via = 'resolucion' | 'correccion';
type Codigo = App.Modules.Multas.Enums.FineReasonCode;

/**
 * Llevar la obligación a otro importe (M04), por una de dos vías.
 *
 * - **Por resolución**, la normal: la que el expediente ya tiene o una nueva
 *   que se carga acá mismo.
 * - **Corrección de importe**, para un importe mal cargado: sin resolución,
 *   con motivo y detalle. Solo la ve quien tiene `multas.corregir-importe`.
 *
 * Se escribe el importe nuevo, no la diferencia: si es aumento o reducción
 * lo dice la vista previa y lo decide el servidor. El importe original no se
 * toca; cada ajuste es otra fila.
 */
export default function AdjustDebtDialog({
    fineId,
    obligacion,
    resoluciones,
    puedeAjustar,
    puedeCorregir,
    onClose,
}: {
    fineId: number;
    /** El importe vigente: contra él se calcula y se verifica el cambio. */
    obligacion: string;
    resoluciones: Documento[];
    puedeAjustar: boolean;
    puedeCorregir: boolean;
    onClose: () => void;
}) {
    const [via, setVia] = useState<Via>(
        puedeAjustar ? 'resolucion' : 'correccion',
    );
    const { data, setData, post, transform, processing, errors, clearErrors } =
        useForm({
            newAmount: '',
            effectiveOn: businessToday(),
            documentId: null as number | null,
            document: { text: '', issuedOn: '' },
            reasonCode: '' as Codigo | '',
            reason: '',
        });

    const errores = errors as Record<string, string | undefined>;
    const porResolucion = via === 'resolucion';

    const cambiarVia = (nueva: Via) => {
        setVia(nueva);
        // Las listas de motivos de cada vía no se cruzan.
        setData((datos) => ({ ...datos, reasonCode: '' }));
        clearErrors();
    };

    const enviar = () => {
        transform((datos) => ({
            newAmount: parseAmount(datos.newAmount),
            expectedAmount: obligacion,
            reasonCode: datos.reasonCode,
            reason: datos.reason,
            ...(porResolucion
                ? {
                      effectiveOn: datos.effectiveOn,
                      ...(datos.documentId !== null
                          ? { documentId: datos.documentId }
                          : { document: datos.document }),
                  }
                : {}),
        }));

        post(porResolucion ? store(fineId).url : corregir(fineId).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <FormDialog
            title={
                porResolucion ? 'Ajustar la obligación' : 'Corregir el importe'
            }
            description={
                porResolucion
                    ? 'Cuando una resolución cambia el importe. Los pagos ya hechos no cambian: si la obligación queda por debajo de lo pagado, la diferencia es excedente.'
                    : 'Para un importe mal cargado, sin resolución. Queda como un movimiento más, con su motivo, y se puede revertir. Los pagos ya hechos no cambian.'
            }
            onClose={onClose}
            onSubmit={enviar}
            processing={processing}
            submitLabel={
                porResolucion ? 'Registrar ajuste' : 'Corregir importe'
            }
        >
            {puedeAjustar && puedeCorregir && (
                <div
                    className="-mt-2 flex border-b"
                    aria-label="Vía del ajuste"
                >
                    <ListViewTab
                        active={porResolucion}
                        onClick={() => cambiarVia('resolucion')}
                    >
                        Por resolución
                    </ListViewTab>
                    <ListViewTab
                        active={!porResolucion}
                        onClick={() => cambiarVia('correccion')}
                    >
                        Corrección de importe
                    </ListViewTab>
                </div>
            )}

            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <p className="text-sm font-medium">Importe vigente</p>
                    <p className="mt-2 text-sm">
                        <Money value={obligacion} />
                    </p>
                </div>
                <div>
                    <Label htmlFor="ajuste-importe">Nuevo importe</Label>
                    <div className="mt-1">
                        <AmountInput
                            id="ajuste-importe"
                            value={data.newAmount}
                            onChange={(valor) => setData('newAmount', valor)}
                            placeholder="0,00"
                            aria-invalid={Boolean(errors.newAmount)}
                            aria-describedby="ajuste-previa"
                        />
                    </div>
                    <FieldError message={errors.newAmount} />
                </div>
            </div>
            <VistaPrevia
                vigente={obligacion}
                nuevo={parseAmount(data.newAmount)}
            />

            {porResolucion && (
                <>
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
                            <SelectTrigger
                                id="ajuste-resolucion"
                                className="mt-1"
                            >
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
                                    aria-invalid={Boolean(
                                        errores['document.text'],
                                    )}
                                />
                                <FieldError
                                    message={errores['document.text']}
                                />
                            </div>
                            <div>
                                <Label htmlFor="ajuste-doc-fecha">
                                    Fecha de la resolución
                                </Label>
                                <DateInput
                                    id="ajuste-doc-fecha"
                                    className="mt-1"
                                    value={data.document.issuedOn}
                                    onChange={(e) =>
                                        setData('document', {
                                            ...data.document,
                                            issuedOn: e.target.value,
                                        })
                                    }
                                    aria-invalid={Boolean(
                                        errores['document.issuedOn'],
                                    )}
                                />
                                <FieldError
                                    message={errores['document.issuedOn']}
                                />
                            </div>
                        </div>
                    )}

                    <div>
                        <Label htmlFor="ajuste-fecha">Fecha del ajuste</Label>
                        <DateInput
                            id="ajuste-fecha"
                            className="mt-1 max-w-48"
                            value={data.effectiveOn}
                            onChange={(e) =>
                                setData('effectiveOn', e.target.value)
                            }
                            aria-invalid={Boolean(errors.effectiveOn)}
                        />
                        <FieldError message={errors.effectiveOn} />
                    </div>
                </>
            )}

            <ReasonFields
                key={via}
                act={porResolucion ? 'adjustment' : 'amount_correction'}
                code={data.reasonCode}
                detail={data.reason}
                onCodeChange={(codigo) => setData('reasonCode', codigo)}
                onDetailChange={(detalle) => setData('reason', detalle)}
                codeError={errors.reasonCode}
                detailError={errors.reason}
                idPrefix="ajuste-motivo"
                detailRequired={!porResolucion}
                detailPlaceholder={
                    porResolucion
                        ? 'Qué dispone la resolución.'
                        : 'Qué estaba mal cargado y de dónde sale el importe correcto.'
                }
            />
        </FormDialog>
    );
}

/** «Reducción de $ X · queda en $ Y», mientras se escribe. */
function VistaPrevia({ vigente, nuevo }: { vigente: string; nuevo: string }) {
    if (nuevo === '' || nuevo.startsWith('-')) {
        return null;
    }

    const comparacion = compareAmounts(nuevo, vigente);

    return (
        <p id="ajuste-previa" className="-mt-2 text-sm text-muted-foreground">
            {comparacion === 0 ? (
                'Es el mismo importe que la multa ya tiene.'
            ) : (
                <>
                    {comparacion > 0 ? 'Aumento' : 'Reducción'} de{' '}
                    <Money
                        value={
                            comparacion > 0
                                ? subtractAmounts(nuevo, vigente)
                                : subtractAmounts(vigente, nuevo)
                        }
                    />{' '}
                    · queda en <Money value={nuevo} />
                </>
            )}
        </p>
    );
}
