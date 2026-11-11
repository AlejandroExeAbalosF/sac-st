/*
 * La configuración única de pdf.js.
 *
 * Este módulo se importa siempre en forma diferida —con `import()` o desde
 * un componente cargado con `lazy()`—: pdf.js pesa lo suyo y la mayoría de
 * las pantallas no muestra ningún PDF.
 */
import { pdfjs } from 'react-pdf';

/*
 * Los archivos los copia `vite.config.ts` a `public/vendor/pdfjs/`, en el
 * mismo origen que la página: un Worker de otro origen no se puede crear, y
 * en desarrollo Vite sirve desde otro puerto.
 */
const ASSETS = '/vendor/pdfjs/';

pdfjs.GlobalWorkerOptions.workerSrc = `${ASSETS}pdf.worker.min.js`;

/**
 * Las opciones de cada documento.
 *
 * `useWasm: false` porque la CSP no habilita `'wasm-unsafe-eval'`: los
 * decodificadores de JPEG 2000 y JBIG2 usan su versión en JavaScript, más
 * lenta pero sin abrir la política.
 *
 * Un objeto estable: react-pdf lo compara por referencia y recarga el
 * documento si cambia.
 */
export const pdfDocumentOptions = {
    cMapUrl: `${ASSETS}cmaps/`,
    standardFontDataUrl: `${ASSETS}standard_fonts/`,
    iccUrl: `${ASSETS}iccs/`,
    wasmUrl: `${ASSETS}wasm/`,
    useWasm: false,
} as const;

/**
 * Dibuja la primera página como imagen, para la miniatura.
 *
 * Recibe la URL de un adjunto guardado —se pide con la sesión, del mismo
 * origen— o el archivo que el operador acaba de elegir. Nunca lanza: si el
 * PDF no se puede leer, devuelve `null` y la tarjeta muestra el ícono.
 *
 * Se dibuja por ancho: la tarjeta muestra el comienzo de la hoja ampliado
 * al ancho completo, como una hoja vista de cerca.
 */
export async function renderPdfFirstPage(
    source: string | Blob,
    targetWidth = 720,
): Promise<Blob | null> {
    try {
        const task = pdfjs.getDocument(
            typeof source === 'string'
                ? { url: source, ...pdfDocumentOptions }
                : {
                      data: new Uint8Array(await source.arrayBuffer()),
                      ...pdfDocumentOptions,
                  },
        );
        const doc = await task.promise;

        try {
            const page = await doc.getPage(1);
            const base = page.getViewport({ scale: 1 });
            // Tope de alto para hojas muy alargadas.
            const viewport = page.getViewport({
                scale: Math.min(
                    targetWidth / base.width,
                    (targetWidth * 2) / base.height,
                ),
            });
            const canvas = document.createElement('canvas');
            canvas.width = Math.ceil(viewport.width);
            canvas.height = Math.ceil(viewport.height);
            await page.render({ canvas, viewport }).promise;

            return await new Promise<Blob | null>((resolve) =>
                canvas.toBlob(resolve, 'image/webp', 0.85),
            );
        } finally {
            await task.destroy();
        }
    } catch {
        return null;
    }
}
