import { useForm } from '@inertiajs/react';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import FormDialog, {
    FieldError,
} from '@/features/multas/components/form-dialog';

/**
 * Un acto que solo pide el motivo: revertir un ajuste.
 */
export default function ReasonDialog({
    title,
    description,
    url,
    submitLabel,
    onClose,
}: {
    title: string;
    description: string;
    url: string;
    submitLabel: string;
    onClose: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({ reason: '' });

    return (
        <FormDialog
            title={title}
            description={description}
            onClose={onClose}
            onSubmit={() =>
                post(url, { preserveScroll: true, onSuccess: onClose })
            }
            processing={processing}
            submitLabel={submitLabel}
            destructive
        >
            <div>
                <Label htmlFor="motivo">Motivo</Label>
                <Textarea
                    id="motivo"
                    className="mt-1"
                    rows={3}
                    maxLength={500}
                    autoFocus
                    value={data.reason}
                    onChange={(e) => setData('reason', e.target.value)}
                    placeholder="Qué pasó."
                    aria-invalid={Boolean(errors.reason)}
                />
                <FieldError message={errors.reason} />
            </div>
        </FormDialog>
    );
}
