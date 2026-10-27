import { useForm } from '@inertiajs/react';
import ReasonFields from '@/components/reason-fields';
import FormDialog from '@/features/multas/components/form-dialog';

type Acto = App.Modules.Multas.Enums.FineReasonAct;
type Codigo = App.Modules.Multas.Enums.FineReasonCode;

/**
 * Un acto que solo pide el motivo: revertir un ajuste, descartar un pago,
 * desvincular un documento. Cada uno con su lista.
 */
export default function ReasonDialog({
    act,
    title,
    description,
    url,
    submitLabel,
    onClose,
}: {
    act: Acto;
    title: string;
    description: string;
    url: string;
    submitLabel: string;
    onClose: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        reasonCode: '' as Codigo | '',
        reason: '',
    });

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
            <ReasonFields
                act={act}
                code={data.reasonCode}
                detail={data.reason}
                onCodeChange={(codigo) => setData('reasonCode', codigo)}
                onDetailChange={(detalle) => setData('reason', detalle)}
                codeError={errors.reasonCode}
                detailError={errors.reason}
                detailPlaceholder="Qué pasó."
            />
        </FormDialog>
    );
}
