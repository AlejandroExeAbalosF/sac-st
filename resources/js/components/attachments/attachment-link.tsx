import type { ReactNode } from 'react';
import type { Attachment } from './types';
import { canPreview, downloadUrl, remoteItem } from './types';
import { useAttachmentViewer } from './use-attachment-viewer';

/**
 * Un enlace a un archivo dentro de una fila de acciones: «Ver comprobante»,
 * «Ver foto».
 *
 * - Si es foto o PDF, abre el visor sin salir de la pantalla.
 * - Si es otro tipo, lo descarga.
 * - Si quien mira no lo puede abrir, no aparece: ofrecerlo terminaba en
 *   un 403.
 */
export function AttachmentLink({
    attachment,
    className,
    children,
}: {
    attachment: Attachment | null;
    className?: string;
    children: ReactNode;
}) {
    const { open, viewer } = useAttachmentViewer();

    if (attachment === null || !attachment.canOpen) {
        return null;
    }

    if (!canPreview(attachment)) {
        return (
            <a href={downloadUrl(attachment)} className={className}>
                {children}
            </a>
        );
    }

    return (
        <>
            <button
                type="button"
                onClick={() => open([remoteItem(attachment)])}
                className={className}
            >
                {children}
            </button>
            {viewer}
        </>
    );
}
