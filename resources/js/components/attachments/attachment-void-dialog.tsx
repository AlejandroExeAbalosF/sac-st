import { useForm, usePage } from '@inertiajs/react';
import ReasonFields from '@/components/reason-fields';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { voidMethod } from '@/routes/adjuntos';
import type { Attachment } from './types';

type Opcion = { value: string; label: string };

/**
 * Anular un adjunto: el motivo y nada más.
 *
 * La lista de motivos llega en la prop compartida `attachmentReasons`, no
 * en `motivos`: esa la define cada pantalla con los actos de su módulo, y
 * esto se abre desde cualquiera.
 */
export function AttachmentVoidDialog({
    attachment,
    onClose,
    onVoided,
}: {
    attachment: Attachment;
    onClose: () => void;
    /** Después de anular: el visor se cierra, la pantalla ya se recargó. */
    onVoided?: () => void;
}) {
    const { attachmentReasons } = usePage<{
        attachmentReasons?: Record<string, Opcion[]>;
    }>().props;
    const { data, setData, post, processing, errors } = useForm({
        reasonCode: '' as App.Modules.Shared.Enums.SharedReasonCode | '',
        reason: '',
    });

    const enviar = (event: React.FormEvent) => {
        event.preventDefault();
        post(voidMethod(attachment.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                onClose();
                onVoided?.();
            },
        });
    };

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Anular «{attachment.title}»</DialogTitle>
                    <DialogDescription>
                        Deja de verse como vigente. El archivo queda guardado en
                        el historial.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={enviar} className="grid gap-4">
                    <ReasonFields
                        act="void_attachment"
                        options={attachmentReasons?.void_attachment}
                        code={data.reasonCode}
                        detail={data.reason}
                        onCodeChange={(codigo) => setData('reasonCode', codigo)}
                        onDetailChange={(detalle) => setData('reason', detalle)}
                        codeError={errors.reasonCode}
                        detailError={errors.reason}
                        idPrefix={`anular-adjunto-${attachment.id}`}
                    />

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={processing}
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={processing}
                        >
                            Anular
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
