import { useForm } from '@inertiajs/react';
import PaperPhotoDropzone from '@/components/paper-photo-dropzone';
import ReasonFields from '@/components/reason-fields';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import DocumentFields, {
    EMPTY_DOCUMENT,
} from '@/features/multas/components/document-fields';
import FormDialog, {
    FieldError,
} from '@/features/multas/components/form-dialog';
import { store } from '@/routes/multas/resolucion-de-origen';

type Documento = App.Modules.Multas.Data.FineDocumentData;
type Codigo = App.Modules.Multas.Enums.FineReasonCode;

/**
 * Reemplaza la resolución de origen cuando se cargó la equivocada: otro
 * papel, no una lectura —eso se corrige en el documento—.
 *
 * Ofrece las otras resoluciones del expediente o cargar la que falta, con
 * su fecha y su archivo. El importe no cambia; la resolución actual queda
 * desvinculada como origen, a la vista (`ReplaceFineOriginResolution`).
 */
export default function ReplaceOriginDialog({
    fineId,
    actual,
    resoluciones,
    onClose,
}: {
    fineId: number;
    actual: Documento;
    /** Las otras resoluciones del expediente. */
    resoluciones: Documento[];
    onClose: () => void;
}) {
    const { data, setData, post, transform, processing, errors } = useForm({
        documentId: (resoluciones[0]?.id ?? null) as number | null,
        document: EMPTY_DOCUMENT,
        file: null as File | null,
        reasonCode: '' as Codigo | '',
        reason: '',
    });

    const errores = errors as Record<string, string | undefined>;

    const enviar = () => {
        transform((datos) => ({
            reasonCode: datos.reasonCode,
            reason: datos.reason,
            ...(datos.documentId !== null
                ? { documentId: datos.documentId }
                : {
                      document: {
                          text: datos.document.text,
                          issuedOn: datos.document.issuedOn,
                      },
                      ...(datos.file !== null && { file: datos.file }),
                  }),
        }));

        post(store(fineId).url, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <FormDialog
            title={`Reemplazar ${actual.label} como origen`}
            description="Cuando se cargó la resolución equivocada. El importe original pasa a citar la nueva, con su fecha; el importe no cambia. La actual queda desvinculada como origen, a la vista, con el motivo."
            onClose={onClose}
            onSubmit={enviar}
            processing={processing}
            submitLabel="Reemplazar"
            destructive
        >
            <div>
                <Label htmlFor="origen-documento">Resolución de origen</Label>
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
                    <SelectTrigger id="origen-documento" className="mt-1">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {resoluciones.map((d) => (
                            <SelectItem key={d.id} value={String(d.id)}>
                                {d.label}
                            </SelectItem>
                        ))}
                        <SelectItem value="nueva">
                            Cargar una resolución nueva
                        </SelectItem>
                    </SelectContent>
                </Select>
                <FieldError message={errors.documentId} />
            </div>

            {data.documentId === null && (
                <>
                    <DocumentFields
                        value={data.document}
                        onChange={(documento) => setData('document', documento)}
                        errors={errores}
                        idPrefix="origen-nueva"
                        kinds={['resolution']}
                        dateRequired
                    />
                    <PaperPhotoDropzone
                        id="origen-nueva-archivo"
                        label="Archivo de la resolución"
                        foto={data.file}
                        onElegir={(archivo) => setData('file', archivo)}
                        error={errores.file}
                    />
                </>
            )}

            <ReasonFields
                act="origin_replacement"
                code={data.reasonCode}
                detail={data.reason}
                onCodeChange={(codigo) => setData('reasonCode', codigo)}
                onDetailChange={(detalle) => setData('reason', detalle)}
                codeError={errors.reasonCode}
                detailError={errors.reason}
                idPrefix="origen-motivo"
            />
        </FormDialog>
    );
}
