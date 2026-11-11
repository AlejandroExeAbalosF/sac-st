import { Download, Lock } from 'lucide-react';
import { cn } from '@/lib/utils';
import { AttachmentThumbnail } from './attachment-preview';
import type { Attachment } from './types';
import { canPreview, downloadUrl, remoteItem } from './types';
import { useAttachmentViewer } from './use-attachment-viewer';

/**
 * Una miniatura chica para una fila: el comprobante de una cuota, la foto
 * de un recibo de papel, el informe de un egreso.
 *
 * Deja ver de un vistazo qué papel es —una foto o la primera hoja del
 * PDF— y un clic lo abre en el visor. Si es otro tipo de archivo, lo
 * descarga; si quien mira no lo puede abrir, se ve el candado y no se pide
 * nada.
 */
export function AttachmentMini({
    attachment,
    className,
}: {
    attachment: Attachment;
    className?: string;
}) {
    const { open, viewer } = useAttachmentViewer();
    const marco = cn(
        'relative block h-12 w-16 shrink-0 overflow-hidden rounded-md border bg-muted',
        className,
    );

    if (!attachment.canOpen) {
        return (
            <span
                className={cn(
                    marco,
                    'flex items-center justify-center text-muted-foreground',
                )}
                title={`${attachment.title}: no tenés permiso para abrirlo`}
            >
                <Lock className="size-4" aria-hidden="true" />
                <span className="sr-only">
                    {attachment.title}: sin permiso para abrirlo
                </span>
            </span>
        );
    }

    if (!canPreview(attachment)) {
        return (
            <a
                href={downloadUrl(attachment)}
                className={cn(
                    marco,
                    'flex items-center justify-center text-muted-foreground hover:text-foreground',
                )}
                title={`Descargar ${attachment.title}`}
            >
                <Download className="size-4" aria-hidden="true" />
                <span className="sr-only">Descargar {attachment.title}</span>
            </a>
        );
    }

    return (
        <>
            <button
                type="button"
                onClick={() => open([remoteItem(attachment)])}
                aria-label={`Ver ${attachment.title}`}
                title={`Ver ${attachment.title}`}
                className={cn(
                    marco,
                    'cursor-zoom-in transition-shadow hover:shadow-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                )}
            >
                <AttachmentThumbnail item={remoteItem(attachment)} />
            </button>
            {viewer}
        </>
    );
}
