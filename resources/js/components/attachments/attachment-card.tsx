import { Download, Lock, MoreVertical, Pencil, ZoomIn } from 'lucide-react';
import { useRef, useState } from 'react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { dateTime, fileSize } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
    AttachmentThumbnail,
    extensionOf,
    FileKindBadge,
} from './attachment-preview';
import {
    AttachmentTitleEditor,
    renameAttachment,
} from './attachment-title-editor';
import type { Attachment } from './types';
import { canPreview, downloadUrl, remoteItem } from './types';

/*
 * El detalle se despliega al pasar el mouse, al llegar con el teclado o, en
 * una pantalla táctil, siempre: ahí no hay hover que lo muestre.
 */
const revelar =
    'opacity-0 transition-opacity duration-200 group-hover:opacity-100 group-focus-within:opacity-100 group-data-[active=true]:opacity-100 pointer-coarse:opacity-100 motion-reduce:transition-none';
const desplegar =
    'grid grid-rows-[0fr] opacity-0 transition-[grid-template-rows,opacity] duration-300 ease-out group-hover:grid-rows-[1fr] group-hover:opacity-100 group-focus-within:grid-rows-[1fr] group-focus-within:opacity-100 group-data-[active=true]:grid-rows-[1fr] group-data-[active=true]:opacity-100 pointer-coarse:grid-rows-[1fr] pointer-coarse:opacity-100 motion-reduce:transition-none';

/**
 * Un archivo en mosaico: la miniatura ocupa toda la tarjeta y encima van
 * el tipo, el nombre y, al acercarse, quién lo subió, cuándo y el menú ⋮:
 * descargar y, con permiso, corregir la descripción.
 *
 * El archivo en sí no se modifica: corregir es solo la descripción.
 */
export function AttachmentCard({
    attachment,
    onOpen,
    className,
}: {
    attachment: Attachment;
    /** Abre el visor; sin él la tarjeta no se puede ampliar. */
    onOpen?: () => void;
    className?: string;
}) {
    const [editando, setEditando] = useState(false);
    const [menuAbierto, setMenuAbierto] = useState(false);
    const [error, setError] = useState<string | null>(null);
    /*
     * Al cerrarse, el menú devuelve el foco a su botón. Si se eligió
     * corregir, ese foco se lo sacaría al campo recién abierto y el
     * `onBlur` lo cerraría sin guardar.
     */
    const aEditar = useRef(false);
    const extension = extensionOf(attachment.originalFilename);
    const ampliable = onOpen !== undefined && canPreview(attachment);
    const detalle = [
        fileSize(attachment.sizeBytes),
        attachment.createdByName ?? 'Sin dato',
        dateTime(attachment.createdAt),
    ].join(' · ');

    return (
        <div
            data-active={editando || menuAbierto}
            className={cn(
                'group relative aspect-[4/3] overflow-hidden rounded-lg border bg-muted shadow-xs transition-shadow duration-200 hover:shadow-md',
                className,
            )}
        >
            <div className="absolute inset-0 transition-transform duration-500 ease-out group-hover:scale-105 motion-reduce:transition-none motion-reduce:group-hover:scale-100">
                <AttachmentThumbnail item={remoteItem(attachment)} />
            </div>

            {ampliable && (
                <button
                    type="button"
                    onClick={onOpen}
                    aria-label={`Ver ${attachment.title}`}
                    className="peer absolute inset-0 cursor-zoom-in focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
                />
            )}

            {ampliable && (
                <span className="pointer-events-none absolute inset-0 flex items-center justify-center opacity-0 transition-opacity duration-200 group-hover:opacity-100 peer-focus-visible:opacity-100">
                    <span className="rounded-full bg-black/40 p-2.5 text-white shadow-lg backdrop-blur-sm">
                        <ZoomIn className="size-5" />
                    </span>
                </span>
            )}

            <FileKindBadge
                kind={attachment.kind}
                label={extension || attachment.kind}
                className="absolute top-2 right-2"
            />

            <div className="pointer-events-none absolute inset-x-0 bottom-0 bg-linear-to-t from-black/80 via-black/55 to-transparent px-2.5 pt-5 pb-2 text-white">
                <div className="relative">
                    {editando ? (
                        <AttachmentTitleEditor
                            initial={attachment.title}
                            tone="dark"
                            error={error}
                            onDone={(valor) => {
                                if (valor === null) {
                                    setEditando(false);
                                    setError(null);

                                    return;
                                }

                                renameAttachment(attachment.id, valor, {
                                    onSuccess: () => {
                                        setEditando(false);
                                        setError(null);
                                    },
                                    onError: setError,
                                });
                            }}
                        />
                    ) : (
                        <p
                            className="truncate pr-7 text-sm leading-tight font-medium drop-shadow-sm"
                            title={attachment.originalFilename}
                        >
                            {attachment.title}
                        </p>
                    )}
                    <div className={cn(desplegar, editando && 'hidden')}>
                        <div className="overflow-hidden">
                            <p className="truncate pt-0.5 text-[0.6875rem] text-white/80">
                                {attachment.canOpen
                                    ? detalle
                                    : 'Sin permiso para abrirlo'}
                            </p>
                        </div>
                    </div>

                    {editando ? null : attachment.canOpen ? (
                        <DropdownMenu
                            modal={false}
                            open={menuAbierto}
                            onOpenChange={setMenuAbierto}
                        >
                            <DropdownMenuTrigger asChild>
                                <button
                                    type="button"
                                    aria-label={`Acciones de ${attachment.title}`}
                                    className={cn(
                                        'pointer-events-auto absolute -right-1 -bottom-0.5 flex size-7 items-center justify-center rounded-full hover:bg-white/20 focus-visible:ring-2 focus-visible:ring-white/80 focus-visible:outline-none',
                                        revelar,
                                    )}
                                >
                                    <MoreVertical className="size-4" />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                align="end"
                                className="w-52"
                                onCloseAutoFocus={(e) => {
                                    if (aEditar.current) {
                                        e.preventDefault();
                                        aEditar.current = false;
                                    }
                                }}
                            >
                                <DropdownMenuItem asChild>
                                    <a href={downloadUrl(attachment)}>
                                        <Download className="size-4" />
                                        Descargar
                                    </a>
                                </DropdownMenuItem>
                                {attachment.canRename && (
                                    <DropdownMenuItem
                                        onSelect={() => {
                                            aEditar.current = true;
                                            setEditando(true);
                                        }}
                                    >
                                        <Pencil className="size-4" />
                                        Corregir descripción
                                    </DropdownMenuItem>
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    ) : (
                        <Lock
                            className="absolute right-0.5 bottom-0.5 size-3.5 text-white/80"
                            aria-label="Sin permiso para abrirlo"
                        />
                    )}
                </div>
            </div>
        </div>
    );
}
