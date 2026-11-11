import { cn } from '@/lib/utils';
import { AttachmentCard } from './attachment-card';
import type { Attachment } from './types';
import { canPreview, remoteItem } from './types';
import { useAttachmentViewer } from './use-attachment-viewer';

/**
 * Los archivos de algo —una multa, un pago, un documento— en mosaico.
 *
 * Un clic en cualquiera abre el visor con todos los que se pueden mirar,
 * para pasar de uno a otro sin cerrar. Los que no tienen vista previa, o
 * que quien mira no puede abrir, quedan en la grilla pero no entran al
 * visor.
 */
export function AttachmentGallery({
    attachments,
    className,
}: {
    attachments: Attachment[];
    className?: string;
}) {
    const { open, viewer } = useAttachmentViewer();
    const visibles = attachments.filter(canPreview);
    const items = visibles.map(remoteItem);

    return (
        <>
            <ul
                className={cn(
                    'grid grid-cols-[repeat(auto-fill,minmax(9rem,1fr))] gap-2',
                    className,
                )}
            >
                {attachments.map((adjunto) => {
                    const indice = visibles.indexOf(adjunto);

                    return (
                        <li key={adjunto.id}>
                            <AttachmentCard
                                attachment={adjunto}
                                onOpen={
                                    indice === -1
                                        ? undefined
                                        : () => open(items, indice)
                                }
                            />
                        </li>
                    );
                })}
            </ul>
            {viewer}
        </>
    );
}
