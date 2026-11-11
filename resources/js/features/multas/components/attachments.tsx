import { useForm } from '@inertiajs/react';
import PaperPhotoDropzone from '@/components/paper-photo-dropzone';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import FormDialog, {
    FieldError,
} from '@/features/multas/components/form-dialog';

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
