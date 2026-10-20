import { useForm } from '@inertiajs/react';
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
import DocumentFields, {
    EMPTY_DOCUMENT,
} from '@/features/multas/components/document-fields';
import FormDialog, {
    FieldError,
} from '@/features/multas/components/form-dialog';
import { ACT_TYPE } from '@/features/multas/labels';
import { store as storeRevisionDeDocumento } from '@/routes/multas/documentos/revisiones';
import { store as storeDocumento } from '@/routes/multas/expedientes/documentos';
import { store as storeRevisionDeExpediente } from '@/routes/multas/expedientes/revisiones';

type Expediente = App.Modules.Multas.Data.FineCaseData;
type Documento = App.Modules.Multas.Data.FineDocumentData;
type ActType = App.Modules.Multas.Enums.FineActType;

/**
 * Agrega una resolución, un acta u otro papel al expediente. Se puede
 * hacer antes de cargar la multa que lo usa.
 */
export function AddDocumentDialog({
    caseId,
    onClose,
}: {
    caseId: number;
    onClose: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        document: EMPTY_DOCUMENT,
    });

    return (
        <FormDialog
            title="Agregar un documento al expediente"
            description="Se escribe como figura en el papel; el sistema lee de ahí el número y el año."
            onClose={onClose}
            onSubmit={() =>
                post(storeDocumento(caseId).url, {
                    preserveScroll: true,
                    onSuccess: onClose,
                })
            }
            processing={processing}
            submitLabel="Agregar"
        >
            <DocumentFields
                value={data.document}
                onChange={(documento) => setData('document', documento)}
                errors={errors as Record<string, string | undefined>}
                idPrefix="nuevo-documento"
            />
        </FormDialog>
    );
}

/**
 * Completar o corregir número, año y sufijo del expediente. El texto del
 * papel queda como estaba.
 */
export function ReviseCaseDialog({
    expediente,
    onClose,
}: {
    expediente: Expediente;
    onClose: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        number: expediente.number?.toString() ?? '',
        year: expediente.year?.toString() ?? '',
        suffix: expediente.suffix ?? '',
        reason: '',
        /*
         * Lo que mostraba el formulario al abrirse. El servidor compara
         * contra esto: no pisa lo que otro operador cambió mientras tanto.
         */
        expected: {
            number: expediente.number?.toString() ?? '',
            year: expediente.year?.toString() ?? '',
            suffix: expediente.suffix ?? '',
        },
    });

    return (
        <FormDialog
            title="Corregir el número del expediente"
            description={
                <>
                    En el papel dice{' '}
                    <span className="font-mono">{expediente.originalText}</span>
                    , y eso no cambia. Si corregís un dato que ya tenía valor,
                    decí por qué.
                </>
            }
            onClose={onClose}
            onSubmit={() =>
                post(storeRevisionDeExpediente(expediente.id).url, {
                    preserveScroll: true,
                    onSuccess: onClose,
                })
            }
            processing={processing}
            submitLabel="Guardar"
        >
            <div className="grid gap-4 sm:grid-cols-3">
                <div>
                    <Label htmlFor="expediente-numero">Número</Label>
                    <Input
                        id="expediente-numero"
                        inputMode="numeric"
                        className="mt-1 font-mono"
                        value={data.number}
                        onChange={(e) => setData('number', e.target.value)}
                        aria-invalid={Boolean(errors.number)}
                    />
                    <FieldError message={errors.number} />
                </div>
                <div>
                    <Label htmlFor="expediente-anio">Año</Label>
                    <Input
                        id="expediente-anio"
                        inputMode="numeric"
                        className="mt-1 font-mono"
                        value={data.year}
                        onChange={(e) => setData('year', e.target.value)}
                        placeholder="2024"
                        aria-invalid={Boolean(errors.year)}
                    />
                    <FieldError message={errors.year} />
                </div>
                <div>
                    <Label htmlFor="expediente-sufijo">Sufijo</Label>
                    <Input
                        id="expediente-sufijo"
                        className="mt-1 font-mono"
                        value={data.suffix}
                        onChange={(e) => setData('suffix', e.target.value)}
                        placeholder="0"
                        aria-invalid={Boolean(errors.suffix)}
                    />
                    <FieldError message={errors.suffix} />
                </div>
            </div>
            <MotivoDeCorreccion
                value={data.reason}
                onChange={(reason) => setData('reason', reason)}
                error={errors.reason}
            />
        </FormDialog>
    );
}

/**
 * Completar o corregir cómo se leyó un documento: tipo de acta, número,
 * año y fecha. El texto del papel queda como estaba.
 */
export function ReviseDocumentDialog({
    documento,
    onClose,
}: {
    documento: Documento;
    onClose: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        actType: documento.actType,
        number: documento.number?.toString() ?? '',
        year: documento.year?.toString() ?? '',
        issuedOn: documento.issuedOn,
        reason: '',
        /*
         * Lo que mostraba el formulario al abrirse. El servidor compara
         * contra esto: no pisa lo que otro operador cambió mientras tanto.
         */
        expected: {
            actType: documento.actType,
            number: documento.number?.toString() ?? '',
            year: documento.year?.toString() ?? '',
            issuedOn: documento.issuedOn,
        },
    });

    return (
        <FormDialog
            title={`Corregir ${documento.label}`}
            description={
                <>
                    En el papel dice{' '}
                    <span className="font-mono">{documento.originalText}</span>,
                    y eso no cambia.
                </>
            }
            onClose={onClose}
            onSubmit={() =>
                post(storeRevisionDeDocumento(documento.id).url, {
                    preserveScroll: true,
                    onSuccess: onClose,
                })
            }
            processing={processing}
            submitLabel="Guardar"
        >
            <div className="grid gap-4 sm:grid-cols-2">
                {documento.kind === 'act' && (
                    <div className="sm:col-span-2">
                        <Label htmlFor="documento-tipo-acta">
                            Tipo de acta
                        </Label>
                        <Select
                            value={data.actType ?? ''}
                            onValueChange={(valor) =>
                                setData('actType', valor as ActType)
                            }
                        >
                            <SelectTrigger
                                id="documento-tipo-acta"
                                className="mt-1"
                            >
                                <SelectValue placeholder="Sin indicar" />
                            </SelectTrigger>
                            <SelectContent>
                                {(Object.keys(ACT_TYPE) as ActType[]).map(
                                    (tipo) => (
                                        <SelectItem key={tipo} value={tipo}>
                                            {tipo} · {ACT_TYPE[tipo]}
                                        </SelectItem>
                                    ),
                                )}
                            </SelectContent>
                        </Select>
                        <FieldError message={errors.actType} />
                    </div>
                )}
                <div>
                    <Label htmlFor="documento-numero">Número</Label>
                    <Input
                        id="documento-numero"
                        inputMode="numeric"
                        className="mt-1 font-mono"
                        value={data.number}
                        onChange={(e) => setData('number', e.target.value)}
                        aria-invalid={Boolean(errors.number)}
                    />
                    <FieldError message={errors.number} />
                </div>
                <div>
                    <Label htmlFor="documento-anio">Año</Label>
                    <Input
                        id="documento-anio"
                        inputMode="numeric"
                        className="mt-1 font-mono"
                        value={data.year}
                        onChange={(e) => setData('year', e.target.value)}
                        aria-invalid={Boolean(errors.year)}
                    />
                    <FieldError message={errors.year} />
                </div>
                <div>
                    <Label htmlFor="documento-fecha">Fecha</Label>
                    <Input
                        id="documento-fecha"
                        className="mt-1"
                        value={data.issuedOn}
                        onChange={(e) => setData('issuedOn', e.target.value)}
                        placeholder="2026-05-07 o 2026-05"
                        aria-invalid={Boolean(errors.issuedOn)}
                    />
                    <FieldError message={errors.issuedOn} />
                </div>
            </div>
            <MotivoDeCorreccion
                value={data.reason}
                onChange={(reason) => setData('reason', reason)}
                error={errors.reason}
            />
        </FormDialog>
    );
}

function MotivoDeCorreccion({
    value,
    onChange,
    error,
}: {
    value: string;
    onChange: (value: string) => void;
    error?: string;
}) {
    return (
        <div>
            <Label htmlFor="correccion-motivo">
                Motivo
                <span className="ml-1 font-normal text-muted-foreground">
                    (si corregís un dato ya cargado)
                </span>
            </Label>
            <Textarea
                id="correccion-motivo"
                className="mt-1"
                rows={2}
                maxLength={500}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                aria-invalid={Boolean(error)}
            />
            <FieldError message={error} />
        </div>
    );
}
