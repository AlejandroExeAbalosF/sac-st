import { useState } from 'react';
import { cn } from '@/lib/utils';
import { AttachmentCard } from './attachment-card';
import type { Attachment } from './types';
import { canPreview, remoteItem } from './types';
import { useAttachmentViewer } from './use-attachment-viewer';

function Grilla({
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

/**
 * Los archivos de algo —una multa, un pago, un documento— en mosaico.
 *
 * Un clic en cualquiera abre el visor con todos los que se pueden mirar,
 * para pasar de uno a otro sin cerrar. Los anulados no se mezclan con los
 * vigentes: quedan detrás de «Ver anulados», con su motivo.
 */
export function AttachmentGallery({
    attachments,
    showVoided = false,
    className,
}: {
    attachments: Attachment[];
    /** En el historial se ven todos juntos, anulados incluidos. */
    showVoided?: boolean;
    className?: string;
}) {
    const [verAnulados, setVerAnulados] = useState(false);

    if (showVoided) {
        return <Grilla attachments={attachments} className={className} />;
    }

    const vigentes = attachments.filter((a) => a.voided === null);
    const anulados = attachments.filter((a) => a.voided !== null);

    return (
        <div className={cn('grid gap-2', className)}>
            {vigentes.length > 0 && <Grilla attachments={vigentes} />}

            {anulados.length > 0 && (
                <>
                    <button
                        type="button"
                        onClick={() => setVerAnulados((v) => !v)}
                        aria-expanded={verAnulados}
                        className="justify-self-start text-xs text-muted-foreground hover:text-foreground hover:underline"
                    >
                        {verAnulados
                            ? 'Ocultar anulados'
                            : `Ver anulados (${anulados.length})`}
                    </button>
                    {verAnulados && <Grilla attachments={anulados} />}
                </>
            )}
        </div>
    );
}
