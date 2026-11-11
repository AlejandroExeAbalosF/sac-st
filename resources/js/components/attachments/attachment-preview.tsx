import { FileSpreadsheet, FileText, ImageIcon, ImageOff } from 'lucide-react';
import { useEffect, useState } from 'react';
import { cn } from '@/lib/utils';
import type { AttachmentKind, ViewerItem } from './types';
import { canPreview, previewUrl } from './types';
import { useInViewOnce } from './use-in-view';
import { useObjectUrl } from './use-object-url';

/** La etiqueta del tipo: PDF, JPG, WEBP. */
export function FileKindBadge({
    kind,
    label,
    className,
}: {
    kind: AttachmentKind;
    label: string;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-md px-1.5 py-0.5 text-[0.625rem] font-bold tracking-wider uppercase shadow-sm',
                kind === 'pdf'
                    ? 'bg-destructive-soft text-destructive-strong'
                    : kind === 'image'
                      ? 'bg-info-soft text-info-strong'
                      : 'bg-muted text-muted-foreground',
                className,
            )}
        >
            {label}
        </span>
    );
}

/** La extensión que se muestra en la etiqueta, a partir del nombre. */
export function extensionOf(filename: string): string {
    const partes = filename.split('.');

    return partes.length > 1 ? (partes.pop() ?? '').toLowerCase() : '';
}

function KindPlaceholder({
    kind,
    broken = false,
}: {
    kind: AttachmentKind;
    broken?: boolean;
}) {
    const Icon = broken
        ? ImageOff
        : kind === 'pdf'
          ? FileText
          : kind === 'image'
            ? ImageIcon
            : FileSpreadsheet;

    return (
        <div className="flex size-full items-center justify-center bg-muted text-muted-foreground">
            <Icon className="size-9" strokeWidth={1.5} aria-hidden="true" />
        </div>
    );
}

const shimmer = <div className="size-full animate-pulse bg-muted" />;

/*
 * Las miniaturas de PDF ya dibujadas, por archivo.
 *
 * Dibujar la primera hoja cuesta: abrir el documento, decodificarlo y
 * pintarlo en un canvas. La misma miniatura aparece en la tarjeta y en la
 * tira del visor, y volver a una pantalla no tiene por qué repetirlo. Un
 * adjunto guardado no cambia nunca, así que una miniatura lograda no hay
 * que invalidarla.
 *
 * **Una que falló, sí.** `renderPdfFirstPage` no lanza: un 503 pasajero
 * vuelve como `null`. Guardado, dejaba el ícono roto hasta recargar la
 * aplicación aunque el archivo ya estuviera disponible.
 */
const pdfThumbnails = new Map<string, Promise<Blob | null>>();

export function pdfThumbnail(item: ViewerItem): Promise<Blob | null> {
    const cached = pdfThumbnails.get(item.key);

    if (cached !== undefined) {
        return cached;
    }

    const promesa = import('@/lib/pdf')
        .then(({ renderPdfFirstPage }) =>
            renderPdfFirstPage(
                item.source === 'remote'
                    ? previewUrl(item.attachment)
                    : item.file,
            ),
        )
        .catch(() => null)
        .then((blob) => {
            // Sin miniatura: el próximo montaje lo vuelve a intentar.
            if (blob === null && pdfThumbnails.get(item.key) === promesa) {
                pdfThumbnails.delete(item.key);
            }

            return blob;
        });
    pdfThumbnails.set(item.key, promesa);

    return promesa;
}

function usePdfThumbnail(item: ViewerItem, enabled: boolean) {
    const [state, setState] = useState<{
        key: string;
        blob: Blob | null;
    } | null>(null);

    useEffect(() => {
        if (!enabled) {
            return;
        }

        let vigente = true;
        void pdfThumbnail(item).then((blob) => {
            if (vigente) {
                setState({ key: item.key, blob });
            }
        });

        return () => {
            vigente = false;
        };
    }, [item, enabled]);

    const actual = state?.key === item.key ? state : null;

    return {
        blob: actual?.blob ?? null,
        loading: enabled && actual === null,
        failed: actual !== null && actual.blob === null,
    };
}

function PreviewImage({
    url,
    kind,
    alt,
    onError,
}: {
    url: string;
    kind: AttachmentKind;
    alt: string;
    onError?: () => void;
}) {
    return (
        <img
            src={url}
            alt={alt}
            draggable={false}
            onError={onError}
            className={cn(
                'size-full animate-in duration-300 fade-in-0',
                // Un PDF muestra el comienzo de la hoja, ampliado al ancho.
                kind === 'pdf' ? 'object-cover object-top' : 'object-cover',
            )}
        />
    );
}

/**
 * La miniatura de un archivo, guardado o recién elegido.
 *
 * - Una foto se muestra tal cual: el servidor ya la guarda reducida.
 * - De un PDF se dibuja la primera hoja con pdf.js, recién cuando la
 *   tarjeta entra en pantalla.
 * - Sin permiso para abrirlo, o si no es foto ni PDF, se muestra el ícono
 *   del tipo y **no se pide nada** al servidor.
 */
export function AttachmentThumbnail({ item }: { item: ViewerItem }) {
    const { ref, inView } = useInViewOnce<HTMLDivElement>();
    const [broken, setBroken] = useState(false);
    const localUrl = useObjectUrl(
        item.source === 'local' && item.kind === 'image' ? item.file : null,
    );

    const permitido =
        item.source === 'local'
            ? item.kind !== 'other'
            : canPreview(item.attachment);
    const pdf = usePdfThumbnail(
        item,
        permitido && item.kind === 'pdf' && inView,
    );
    const pdfUrl = useObjectUrl(pdf.blob);

    let contenido;

    if (!permitido) {
        contenido = <KindPlaceholder kind={item.kind} />;
    } else if (broken || pdf.failed) {
        contenido = <KindPlaceholder kind={item.kind} broken />;
    } else if (item.kind === 'image') {
        const url =
            item.source === 'local' ? localUrl : previewUrl(item.attachment);
        contenido =
            inView && url !== null ? (
                <PreviewImage
                    url={url}
                    kind="image"
                    alt={item.name}
                    onError={() => setBroken(true)}
                />
            ) : (
                shimmer
            );
    } else {
        contenido =
            pdfUrl !== null ? (
                <PreviewImage url={pdfUrl} kind="pdf" alt={item.name} />
            ) : (
                shimmer
            );
    }

    return (
        <div ref={ref} className="size-full">
            {contenido}
        </div>
    );
}
