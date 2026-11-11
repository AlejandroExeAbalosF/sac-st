import * as DialogPrimitive from '@radix-ui/react-dialog';
import {
    ChevronLeft,
    ChevronRight,
    Download,
    Loader2,
    RotateCw,
    X,
    ZoomIn,
    ZoomOut,
} from 'lucide-react';
import { lazy, Suspense, useEffect, useRef, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import { fileSize } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
    AttachmentThumbnail,
    extensionOf,
    FileKindBadge,
} from './attachment-preview';
import { ImageStage } from './image-stage';
import type { ViewerItem } from './types';
import { downloadUrl, previewUrl } from './types';
import { useObjectUrl } from './use-object-url';

// pdf.js se descarga recién cuando se abre un PDF.
const PdfStage = lazy(() => import('./pdf-stage'));

const ZOOM_STEPS = [0.5, 0.75, 1, 1.25, 1.5, 2, 3, 4];

export function nextZoom(zoom: number, dir: 1 | -1): number {
    if (dir > 0) {
        return ZOOM_STEPS.find((z) => z > zoom + 0.001) ?? zoom;
    }

    return [...ZOOM_STEPS].reverse().find((z) => z < zoom - 0.001) ?? zoom;
}

function ViewerButton({
    label,
    onClick,
    disabled,
    children,
    className,
}: {
    label: string;
    onClick: () => void;
    disabled?: boolean;
    children: ReactNode;
    className?: string;
}) {
    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            onClick={onClick}
            disabled={disabled}
            className={cn(
                'flex size-9 items-center justify-center rounded-full text-white/90 transition-colors',
                'hover:bg-white/15 hover:text-white focus-visible:ring-2 focus-visible:ring-white/70 focus-visible:outline-none',
                'disabled:pointer-events-none disabled:opacity-35',
                className,
            )}
        >
            {children}
        </button>
    );
}

const spinner = (
    <div className="flex size-full flex-col items-center justify-center gap-3 text-sm text-white/75">
        <Loader2 className="size-7 animate-spin" />
        Cargando el archivo…
    </div>
);

function Stage({
    item,
    zoom,
    rotation,
    onZoomChange,
    onPageInfo,
}: {
    item: ViewerItem;
    zoom: number;
    rotation: number;
    onZoomChange: (zoom: number) => void;
    onPageInfo: (info: { current: number; total: number }) => void;
}) {
    const localUrl = useObjectUrl(
        item.source === 'local' && item.kind === 'image' ? item.file : null,
    );

    if (item.kind === 'pdf') {
        return (
            <Suspense fallback={spinner}>
                <PdfStage
                    source={
                        item.source === 'remote'
                            ? previewUrl(item.attachment)
                            : item.file
                    }
                    zoom={zoom}
                    onPageInfo={onPageInfo}
                />
            </Suspense>
        );
    }

    const url =
        item.source === 'remote' ? previewUrl(item.attachment) : localUrl;

    if (url === null) {
        return spinner;
    }

    return (
        <div className="size-full p-4 sm:p-8">
            <ImageStage
                url={url}
                alt={item.name}
                zoom={zoom}
                rotation={rotation}
                onZoomChange={onZoomChange}
            />
        </div>
    );
}

/**
 * El visor a pantalla completa: zoom, rotación, descarga y paso de un
 * archivo al siguiente, con flechas, con la tira de miniaturas o con el
 * teclado.
 *
 * Recibe solo archivos que se pueden mirar: quien lo abre ya filtró los
 * que no tienen vista previa o que el operador no puede pedir.
 */
export default function AttachmentViewer({
    items,
    initialIndex,
    onClose,
}: {
    items: ViewerItem[];
    initialIndex: number;
    onClose: () => void;
}) {
    const [index, setIndex] = useState(initialIndex);
    const [zoom, setZoom] = useState(1);
    const [rotation, setRotation] = useState(0);
    const [pageInfo, setPageInfo] = useState<{
        current: number;
        total: number;
    } | null>(null);
    const filmstripRef = useRef<HTMLDivElement>(null);

    // Si la lista se acorta con el visor abierto, el índice queda acotado.
    const safeIndex = Math.min(index, Math.max(items.length - 1, 0));
    const item = items.at(safeIndex);
    const hasMany = items.length > 1;

    const goTo = (target: number) => {
        if (target < 0 || target >= items.length || target === safeIndex) {
            return;
        }

        setIndex(target);
        setZoom(1);
        setRotation(0);
        setPageInfo(null);
    };

    useEffect(() => {
        const reduce = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;
        filmstripRef.current
            ?.querySelector<HTMLElement>(`[data-index="${safeIndex}"]`)
            ?.scrollIntoView({
                block: 'nearest',
                inline: 'center',
                behavior: reduce ? 'auto' : 'smooth',
            });
    }, [safeIndex]);

    const download = () => {
        if (item === undefined) {
            return;
        }

        const enlace = document.createElement('a');
        enlace.href =
            item.source === 'remote'
                ? downloadUrl(item.attachment)
                : URL.createObjectURL(item.file);
        enlace.download = item.source === 'local' ? item.file.name : '';
        enlace.click();

        if (item.source === 'local') {
            URL.revokeObjectURL(enlace.href);
        }
    };

    const onKeyDown = (e: KeyboardEvent) => {
        if (e.target instanceof HTMLInputElement) {
            return;
        }

        if (e.key === 'ArrowRight') {
            goTo(safeIndex + 1);
        } else if (e.key === 'ArrowLeft') {
            goTo(safeIndex - 1);
        } else if (e.key === '+' || e.key === '=') {
            setZoom((z) => nextZoom(z, 1));
        } else if (e.key === '-') {
            setZoom((z) => nextZoom(z, -1));
        } else if (e.key === '0') {
            setZoom(1);
        } else if ((e.key === 'r' || e.key === 'R') && item?.kind === 'image') {
            setRotation((r) => (r + 90) % 360);
        } else {
            return;
        }

        e.preventDefault();
    };

    const nombre =
        item?.source === 'remote'
            ? item.attachment.originalFilename
            : item?.name;

    return (
        <DialogPrimitive.Root open onOpenChange={(open) => !open && onClose()}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-black/80 backdrop-blur-[3px] data-[state=open]:animate-in data-[state=open]:fade-in-0" />
                <DialogPrimitive.Content
                    aria-describedby={undefined}
                    onKeyDown={onKeyDown}
                    className="fixed inset-0 z-50 flex flex-col text-white outline-none data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-[0.98] motion-reduce:animate-none"
                >
                    <header className="flex shrink-0 items-center gap-3 border-b border-white/10 bg-black/55 px-3 py-2 backdrop-blur-md sm:px-4">
                        {item !== undefined && (
                            <FileKindBadge
                                kind={item.kind}
                                label={extensionOf(nombre ?? '') || item.kind}
                            />
                        )}
                        <div className="min-w-0 flex-1">
                            <DialogPrimitive.Title className="truncate text-sm font-semibold sm:text-base">
                                {item?.name ?? 'Archivo'}
                            </DialogPrimitive.Title>
                            <p className="text-xs text-white/60">
                                {[
                                    hasMany &&
                                        `${safeIndex + 1} de ${items.length}`,
                                    item !== undefined &&
                                        fileSize(item.sizeBytes),
                                    pageInfo !== null &&
                                        `Hoja ${pageInfo.current} de ${pageInfo.total}`,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                        </div>

                        <div className="flex items-center gap-0.5">
                            <ViewerButton
                                label="Alejar (−)"
                                onClick={() => setZoom((z) => nextZoom(z, -1))}
                                disabled={zoom <= ZOOM_STEPS[0]}
                            >
                                <ZoomOut className="size-5" />
                            </ViewerButton>
                            <button
                                type="button"
                                onClick={() => setZoom(1)}
                                title="Tamaño original (0)"
                                className="hidden w-14 rounded-full py-1.5 text-center text-xs text-white/80 tabular-nums hover:bg-white/15 sm:block"
                            >
                                {Math.round(zoom * 100)}%
                            </button>
                            <ViewerButton
                                label="Acercar (+)"
                                onClick={() => setZoom((z) => nextZoom(z, 1))}
                                disabled={
                                    zoom >= ZOOM_STEPS[ZOOM_STEPS.length - 1]
                                }
                            >
                                <ZoomIn className="size-5" />
                            </ViewerButton>
                            {item?.kind === 'image' && (
                                <ViewerButton
                                    label="Rotar (R)"
                                    onClick={() =>
                                        setRotation((r) => (r + 90) % 360)
                                    }
                                >
                                    <RotateCw className="size-5" />
                                </ViewerButton>
                            )}
                            <span className="mx-1 h-6 w-px bg-white/15" />
                            <ViewerButton label="Descargar" onClick={download}>
                                <Download className="size-5" />
                            </ViewerButton>
                            <ViewerButton
                                label="Cerrar (Esc)"
                                onClick={onClose}
                            >
                                <X className="size-5" />
                            </ViewerButton>
                        </div>
                    </header>

                    <div className="relative min-h-0 flex-1">
                        {item !== undefined && (
                            <div
                                key={item.key}
                                className="absolute inset-0 animate-in duration-200 fade-in-0 motion-reduce:animate-none"
                            >
                                <Stage
                                    item={item}
                                    zoom={zoom}
                                    rotation={rotation}
                                    onZoomChange={setZoom}
                                    onPageInfo={setPageInfo}
                                />
                            </div>
                        )}

                        {hasMany && (
                            <>
                                <ViewerButton
                                    label="Anterior (←)"
                                    onClick={() => goTo(safeIndex - 1)}
                                    disabled={safeIndex === 0}
                                    className="absolute top-1/2 left-2 z-10 size-11 -translate-y-1/2 bg-black/40 sm:left-4"
                                >
                                    <ChevronLeft className="size-6" />
                                </ViewerButton>
                                <ViewerButton
                                    label="Siguiente (→)"
                                    onClick={() => goTo(safeIndex + 1)}
                                    disabled={safeIndex === items.length - 1}
                                    className="absolute top-1/2 right-2 z-10 size-11 -translate-y-1/2 bg-black/40 sm:right-4"
                                >
                                    <ChevronRight className="size-6" />
                                </ViewerButton>
                            </>
                        )}
                    </div>

                    {hasMany && (
                        <div
                            ref={filmstripRef}
                            className="flex shrink-0 justify-center-safe gap-2 overflow-x-auto border-t border-white/10 bg-black/55 px-4 py-3 backdrop-blur-md"
                        >
                            {items.map((it, i) => (
                                <button
                                    key={it.key}
                                    type="button"
                                    data-index={i}
                                    onClick={() => goTo(i)}
                                    aria-label={`Ver ${it.name}`}
                                    aria-current={i === safeIndex}
                                    className={cn(
                                        'relative h-14 w-20 shrink-0 overflow-hidden rounded-md bg-white/10 transition-opacity duration-200',
                                        i === safeIndex
                                            ? 'ring-2 ring-white ring-offset-2 ring-offset-black'
                                            : 'opacity-55 hover:opacity-100',
                                    )}
                                >
                                    <AttachmentThumbnail item={it} />
                                </button>
                            ))}
                        </div>
                    )}
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}
