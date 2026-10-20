import { useForm } from '@inertiajs/react';
import { Paperclip } from 'lucide-react';
import PaperPhotoDropzone from '@/components/paper-photo-dropzone';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import FormDialog, {
    FieldError,
} from '@/features/multas/components/form-dialog';
import { dateTime } from '@/lib/format';
import { download, preview } from '@/routes/adjuntos';

type Adjunto = App.Modules.Multas.Data.FineAttachmentData;

/**
 * Sube la foto o el PDF de un papel a un expediente, una multa o un pago
 * anterior. El archivo no se edita ni se borra después: si se subió el
 * equivocado, se sube el correcto con una descripción que lo diga.
 */
export function AttachFileDialog({
    url,
    title,
    description,
    onClose,
}: {
    url: string;
    title: string;
    description: string;
    onClose: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm<{
        file: File | null;
        title: string;
    }>({ file: null, title: '' });

    return (
        <FormDialog
            title={title}
            description={description}
            onClose={onClose}
            onSubmit={() =>
                post(url, {
                    forceFormData: true,
                    preserveScroll: true,
                    onSuccess: onClose,
                })
            }
            processing={processing}
            submitLabel="Adjuntar"
        >
            <PaperPhotoDropzone
                id="adjunto-archivo"
                label="Archivo"
                optional={false}
                foto={data.file}
                onElegir={(archivo) => setData('file', archivo)}
                error={errors.file}
            />

            <div>
                <Label htmlFor="adjunto-descripcion">
                    Descripción
                    <span className="ml-1 font-normal text-muted-foreground">
                        (opcional)
                    </span>
                </Label>
                <Input
                    id="adjunto-descripcion"
                    className="mt-1"
                    maxLength={200}
                    value={data.title}
                    onChange={(e) => setData('title', e.target.value)}
                    placeholder="Resolución 1234/25, foja 3"
                    aria-invalid={Boolean(errors.title)}
                />
                <FieldError message={errors.title} />
            </div>
        </FormDialog>
    );
}

/**
 * Los archivos ya subidos. Una foto o un PDF se abren en otra pestaña; lo
 * demás se descarga. Sin permiso de ver adjuntos se lista el nombre, sin
 * enlace: saber que el papel está cargado también sirve.
 */
export function AttachmentList({
    adjuntos,
    canView,
}: {
    adjuntos: Adjunto[];
    canView: boolean;
}) {
    return (
        <ul className="grid gap-1.5">
            {adjuntos.map((adjunto) => (
                <li
                    key={adjunto.id}
                    className="flex min-w-0 flex-wrap items-center gap-x-2 text-sm"
                >
                    <Paperclip
                        className="size-3.5 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    {canView ? (
                        <a
                            href={
                                adjunto.viewable
                                    ? preview(adjunto.id).url
                                    : download(adjunto.id).url
                            }
                            target={adjunto.viewable ? '_blank' : undefined}
                            rel="noreferrer"
                            className="truncate font-medium text-primary hover:underline"
                            title={adjunto.originalFilename}
                        >
                            {adjunto.title}
                        </a>
                    ) : (
                        <span
                            className="truncate font-medium"
                            title={adjunto.originalFilename}
                        >
                            {adjunto.title}
                        </span>
                    )}
                    <span className="text-xs text-muted-foreground">
                        {adjunto.createdByName ?? 'Sin dato'} ·{' '}
                        {dateTime(adjunto.createdAt)}
                    </span>
                </li>
            ))}
        </ul>
    );
}
