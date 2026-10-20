import { useForm } from '@inertiajs/react';
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
import { store } from '@/routes/multas/documentos';

type Documento = App.Modules.Multas.Data.FineDocumentData;
type Rol = 'origin' | 'ratification' | 'support';

/**
 * Vincula un documento con la multa diciendo qué papel cumple.
 *
 * Es lo que registra una ratificación: la resolución que confirma la multa
 * sin cambiar su importe. La obligación no se mueve.
 */
export default function LinkDocumentDialog({
    fineId,
    documentos,
    onClose,
}: {
    fineId: number;
    documentos: Documento[];
    onClose: () => void;
}) {
    const { data, setData, post, transform, processing, errors } = useForm({
        role: 'ratification' as Rol,
        documentId: (documentos[0]?.id ?? null) as number | null,
        document: EMPTY_DOCUMENT,
    });

    const errores = errors as Record<string, string | undefined>;

    const enviar = () => {
        transform((datos) => ({
            role: datos.role,
            ...(datos.documentId !== null
                ? { documentId: datos.documentId }
                : { document: datos.document }),
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
                    onValueChange={(valor) => setData('role', valor as Rol)}
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
                        {documentos.map((d) => (
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
                />
            )}
        </FormDialog>
    );
}
