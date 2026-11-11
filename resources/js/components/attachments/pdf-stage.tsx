import { AlertTriangle, Loader2 } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';
import { Document, Page } from 'react-pdf';
import 'react-pdf/dist/Page/TextLayer.css';
import { pdfDocumentOptions } from '@/lib/pdf';
import { useInViewOnce } from './use-in-view';

/** Proporción A4, para reservar el alto de las hojas todavía no dibujadas. */
const PLACEHOLDER_RATIO = 1.414;
const MAX_BASE_WIDTH = 900;

function LazyPage({
    pageNumber,
    width,
}: {
    pageNumber: number;
    width: number;
}) {
    // Solo se dibujan las hojas cercanas a la vista: un PDF de cien hojas
    // no traba el navegador.
    const { ref, inView } = useInViewOnce<HTMLDivElement>('1200px');
    const reserva = (
        <div
            style={{ width, height: width * PLACEHOLDER_RATIO }}
            className="bg-white"
        />
    );

    return (
        <div
            ref={ref}
            data-page={pageNumber}
            className="mx-auto w-fit overflow-hidden rounded-sm bg-white shadow-2xl"
        >
            {inView ? (
                <Page
                    pageNumber={pageNumber}
                    width={width}
                    // Sin capa de anotaciones: un enlace dentro de un PDF
                    // subido no se sigue desde el visor.
                    renderAnnotationLayer={false}
                    loading={reserva}
                />
            ) : (
                reserva
            )}
        </div>
    );
}

/**
 * Las hojas de un PDF, una debajo de otra, con el zoom del visor.
 *
 * Se carga en forma diferida —trae pdf.js— y solo cuando se abre un PDF.
 */
export default function PdfStage({
    source,
    zoom,
    onPageInfo,
}: {
    /** La URL del adjunto guardado o el archivo recién elegido. */
    source: string | File;
    zoom: number;
    onPageInfo?: (info: { current: number; total: number }) => void;
}) {
    const [numPages, setNumPages] = useState(0);
    const [containerWidth, setContainerWidth] = useState(0);
    const resizeObserver = useRef<ResizeObserver | null>(null);

    const containerRef = useCallback((node: HTMLDivElement | null) => {
        resizeObserver.current?.disconnect();

        if (node === null) {
            return;
        }

        resizeObserver.current = new ResizeObserver(([entry]) =>
            setContainerWidth(entry.contentRect.width),
        );
        resizeObserver.current.observe(node);
    }, []);

    const baseWidth = Math.min(
        Math.max(containerWidth - 32, 240),
        MAX_BASE_WIDTH,
    );
    const pageWidth = Math.round(baseWidth * zoom);

    const reportCurrentPage = (container: HTMLDivElement) => {
        if (onPageInfo === undefined || numPages === 0) {
            return;
        }

        const middle = container.scrollTop + container.clientHeight / 3;
        let current = 1;
        container.querySelectorAll<HTMLElement>('[data-page]').forEach((el) => {
            if (el.offsetTop <= middle) {
                current = Number(el.dataset.page);
            }
        });
        onPageInfo({ current, total: numPages });
    };

    return (
        <div
            ref={containerRef}
            className="relative size-full overflow-auto overscroll-contain px-4 py-6"
            onScroll={(e) => reportCurrentPage(e.currentTarget)}
        >
            {/*
             * `suspense={false}`: con Suspense, react-pdf manda los errores al
             * límite de errores más cercano e ignora `error`. Un PDF que no
             * abre tumbaba la pantalla entera en lugar de mostrar el aviso.
             */}
            <Document
                suspense={false}
                file={source}
                options={pdfDocumentOptions}
                onLoadSuccess={({ numPages: total }) => {
                    setNumPages(total);
                    onPageInfo?.({ current: 1, total });
                }}
                loading={
                    <div className="flex h-[60vh] items-center justify-center gap-2 text-sm text-white/70">
                        <Loader2 className="size-5 animate-spin" />
                        Abriendo el PDF…
                    </div>
                }
                error={
                    <div className="flex h-[60vh] flex-col items-center justify-center gap-2 text-sm text-white/80">
                        <AlertTriangle className="size-8 text-warning" />
                        No se pudo mostrar el PDF. Probá descargarlo.
                    </div>
                }
                className="flex flex-col items-center gap-4"
            >
                {containerWidth > 0 &&
                    Array.from({ length: numPages }, (_, i) => (
                        <LazyPage
                            key={i + 1}
                            pageNumber={i + 1}
                            width={pageWidth}
                        />
                    ))}
            </Document>
        </div>
    );
}
