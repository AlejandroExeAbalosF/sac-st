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
import { purge } from '@/routes/adjuntos';
import type { Attachment } from './types';

type Opcion = { value: string; label: string };

/**
 * Eliminar del todo un adjunto ya anulado: el motivo y la advertencia de
 * que no tiene vuelta.
 */
export function AttachmentPurgeDialog({
    attachment,
    onClose,
    onPurged,
}: {
    attachment: Attachment;
    onClose: () => void;
    /** Después de eliminar: el visor se cierra, la pantalla ya se recargó. */
    onPurged?: () => void;
}) {
    const { attachmentReasons } = usePage<{
        attachmentReasons?: Record<string, Opcion[]>;
    }>().props;
    const form = useForm({
        reasonCode: '' as App.Modules.Shared.Enums.SharedReasonCode | '',
        reason: '',
    });

    const enviar = (event: React.FormEvent) => {
        event.preventDefault();
        form.delete(purge(attachment.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                onClose();
                onPurged?.();
            },
        });
    };

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        Eliminar «{attachment.title}» definitivamente
                    </DialogTitle>
                    <DialogDescription>
                        El archivo deja de existir y no se puede recuperar. En
                        la auditoría queda qué era y por qué se eliminó.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={enviar} className="grid gap-4">
                    <ReasonFields
                        act="purge_attachment"
                        options={attachmentReasons?.purge_attachment}
                        code={form.data.reasonCode}
                        detail={form.data.reason}
                        onCodeChange={(codigo) =>
                            form.setData('reasonCode', codigo)
                        }
                        onDetailChange={(detalle) =>
                            form.setData('reason', detalle)
                        }
                        codeError={form.errors.reasonCode}
                        detailError={form.errors.reason}
                        idPrefix={`eliminar-adjunto-${attachment.id}`}
                    />

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={form.processing}
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={form.processing}
                        >
                            Eliminar definitivamente
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
