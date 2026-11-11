import { Download, Lock, ZoomIn } from 'lucide-react';
import { useState } from 'react';
import { dateTime, fileSize } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
    AttachmentThumbnail,
    extensionOf,
    FileKindBadge,
} from './attachment-preview';
import type { Attachment } from './types';
import { canPreview, downloadUrl, previewUrl, remoteItem } from './types';
import { useAttachmentViewer } from './use-attachment-viewer';

/**
 * Un archivo a la vista, al costado de los datos que se copiaron de él.
 *
 * - Una foto se muestra entera, a lo ancho del panel.
 * - Un PDF muestra su primera hoja.
 * - Un clic abre el visor, con zoom y descarga, sin salir de la pantalla.
 * - Si el operador no lo puede abrir, se dice que existe y nada más: no
 *   se pide el archivo.
 */
export function AttachmentInlinePreview({
    attachment,
    className,
}: {
    attachment: Attachment;
    className?: string;
}) {
    const { open, viewer } = useAttachmentViewer();
    const [broken, setBroken] = useState(false);
    const extension = extensionOf(attachment.originalFilename);
    const detalle = [
        fileSize(attachment.sizeBytes),
        attachment.createdByName,
        dateTime(attachment.createdAt),
    ]
        .filter(Boolean)
        .join(' · ');

    if (!attachment.canOpen) {
        return (
            <div
                className={cn(
                    'flex flex-col items-center gap-2 rounded-lg border border-dashed px-4 py-10 text-center',
                    className,
                )}
            >
                <Lock className="size-5 text-muted-foreground" />
                <p className="text-sm font-medium">{attachment.title}</p>
                <p className="text-xs text-muted-foreground">
                    Está cargado, pero no tenés permiso para abrirlo.
                </p>
            </div>
        );
    }

    if (!canPreview(attachment)) {
        return (
            <a
                href={downloadUrl(attachment)}
                className={cn(
                    'flex items-center gap-3 rounded-lg border p-3 text-sm hover:bg-muted/50',
                    className,
                )}
            >
                <Download className="size-4 text-muted-foreground" />
                <span className="min-w-0 flex-1 truncate font-medium">
                    {attachment.title}
                </span>
                <FileKindBadge kind="other" label={extension || 'archivo'} />
            </a>
        );
    }

    return (
        <div className={cn('grid gap-1.5', className)}>
            <button
                type="button"
                onClick={() => open([remoteItem(attachment)])}
                aria-label={`Ver ${attachment.title} en grande`}
                className="group relative block w-full cursor-zoom-in overflow-hidden rounded-lg border bg-muted/40 p-2 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                {attachment.kind === 'image' && !broken ? (
                    <img
                        src={previewUrl(attachment)}
                        alt={attachment.title}
                        onError={() => setBroken(true)}
                        className="w-full rounded"
                    />
                ) : (
                    <div className="aspect-[1/1.414] w-full overflow-hidden rounded bg-white">
                        <AttachmentThumbnail item={remoteItem(attachment)} />
                    </div>
                )}

                <FileKindBadge
                    kind={attachment.kind}
                    label={extension || attachment.kind}
                    className="absolute top-4 right-4"
                />
                <span className="pointer-events-none absolute inset-0 flex items-center justify-center opacity-0 transition-opacity duration-200 group-hover:opacity-100 group-focus-visible:opacity-100">
                    <span className="rounded-full bg-black/45 p-3 text-white shadow-lg backdrop-blur-sm">
                        <ZoomIn className="size-6" />
                    </span>
                </span>
            </button>
            <p className="truncate px-1 text-xs text-muted-foreground">
                {detalle}
            </p>
            {viewer}
        </div>
    );
}
