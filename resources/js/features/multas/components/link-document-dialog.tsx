import { useForm } from '@inertiajs/react';
import PaperPhotoDropzone from '@/components/paper-photo-dropzone';
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
import { DOCUMENT_ROLE } from '@/features/multas/labels';
import {
    blocksResolution,
    linkableDocuments,
    linkableKinds,
} from '@/features/multas/link-options';
import { store } from '@/routes/multas/documentos';

type Documento = App.Modules.Multas.Data.FineDocumentData;
type Rol = 'origin' | 'ratification' | 'support';

/**
 * Vincula un documento con la multa diciendo qué papel cumple.
 *
 * Es lo que registra una ratificación: la resolución que confirma la multa
 * sin cambiar su importe. La obligación no se mueve.
 *
 * Solo ofrece lo que el servidor acepta: no repite un documento en el papel
 * que ya cumple, y si la multa ya tiene su resolución de origen, como origen
 * no ofrece otra resolución (`LinkFineDocument`).
 */
export default function LinkDocumentDialog({
    fineId,
    documentos,
    vinculados,
    onClose,
}: {
    fineId: number;
    documentos: Documento[];
    /** Los documentos de la multa, con sus papeles. */
    vinculados: Documento[];
    onClose: () => void;
}) {
    const disponibles = (rol: Rol) =>
        linkableDocuments(documentos, vinculados, rol);
    const tipos = (rol: Rol) => linkableKinds(vinculados, rol);

    const { data, setData, post, transform, processing, errors } = useForm({
        role: 'ratification' as Rol,
        documentId: (disponibles('ratification')[0]?.id ?? null) as
            number | null,
        document: EMPTY_DOCUMENT,
        // La foto del documento nuevo: queda en el documento, no en la multa.
        file: null as File | null,
    });

    const errores = errors as Record<string, string | undefined>;

    // Al cambiar el papel, lo elegido que ya no se ofrece cede su lugar.
    const cambiarPapel = (rol: Rol) => {
        const ofrecidos = disponibles(rol);
        const sigue = ofrecidos.some((d) => d.id === data.documentId);

        setData({
            ...data,
            role: rol,
            documentId: sigue
                ? data.documentId
                : data.documentId === null
                  ? null
                  : (ofrecidos[0]?.id ?? null),
            document: tipos(rol).includes(data.document.kind)
                ? data.document
                : { ...data.document, kind: 'act', actType: 'HYS' },
        });
    };

    const enviar = () => {
        transform((datos) => ({
            role: datos.role,
            ...(datos.documentId !== null
                ? { documentId: datos.documentId }
                : {
                      document: datos.document,
                      ...(datos.file !== null && { file: datos.file }),
                  }),
        }));

        post(store(fineId).url, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <FormDialog
            title="Vincular un documento"
            description="Una ratificación, un acta que llegó después o un papel de respaldo. No cambia la obligación."
            onClose={onClose}
            onSubmit={enviar}
            processing={processing}
            submitLabel="Vincular"
        >
            <div>
                <Label htmlFor="vinculo-papel">Papel</Label>
                <Select
                    value={data.role}
                    onValueChange={(valor) => cambiarPapel(valor as Rol)}
                >
                    <SelectTrigger id="vinculo-papel" className="mt-1">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {(['ratification', 'origin', 'support'] as Rol[]).map(
                            (rol) => (
                                <SelectItem key={rol} value={rol}>
                                    {DOCUMENT_ROLE[rol]}
                                </SelectItem>
                            ),
                        )}
                    </SelectContent>
                </Select>
                {blocksResolution(vinculados, data.role) && (
                    <p className="mt-1 text-xs text-muted-foreground">
                        La multa ya tiene su resolución de origen: como origen
                        se vinculan actas. Una resolución posterior va como
                        ratificación.
                    </p>
                )}
                <FieldError message={errors.role} />
            </div>

            <div>
                <Label htmlFor="vinculo-documento">Documento</Label>
                <Select
                    value={
                        data.documentId === null
                            ? 'nuevo'
                            : String(data.documentId)
                    }
                    onValueChange={(valor) =>
                        setData(
                            'documentId',
                            valor === 'nuevo' ? null : Number(valor),
                        )
                    }
                >
                    <SelectTrigger id="vinculo-documento" className="mt-1">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {disponibles(data.role).map((d) => (
                            <SelectItem key={d.id} value={String(d.id)}>
                                {d.label}
                            </SelectItem>
                        ))}
                        <SelectItem value="nuevo">
                            Cargar un documento nuevo
                        </SelectItem>
                    </SelectContent>
                </Select>
                <FieldError message={errors.documentId} />
            </div>

            {data.documentId === null && (
                <DocumentFields
                    value={data.document}
                    onChange={(documento) => setData('document', documento)}
                    errors={errores}
                    idPrefix="vinculo-nuevo"
                    kinds={tipos(data.role)}
                    loaded={documentos}
                    onUseLoaded={(cargado) =>
                        disponibles(data.role).some((d) => d.id === cargado.id)
                            ? () => setData('documentId', cargado.id)
                            : null
                    }
                />
            )}

            {data.documentId === null && (
                <PaperPhotoDropzone
                    id="vinculo-nuevo-archivo"
                    label="Archivo del documento"
                    foto={data.file}
                    onElegir={(archivo) => setData('file', archivo)}
                    error={errores.file}
                />
            )}
        </FormDialog>
    );
}
