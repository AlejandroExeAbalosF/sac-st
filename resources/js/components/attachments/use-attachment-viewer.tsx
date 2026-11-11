import { lazy, Suspense, useState } from 'react';
import type { ReactNode } from 'react';
import type { ViewerItem } from './types';

// El visor no viaja en el bundle de cada pantalla: se baja al abrirlo.
const AttachmentViewer = lazy(() => import('./attachment-viewer'));

/**
 * Abre el visor de archivos desde cualquier pantalla.
 *
 * Devuelve la función para abrirlo y el nodo que hay que dibujar —una
 * vez— en la pantalla. Recién al abrirlo se descarga el visor, y pdf.js
 * solo si lo que se abre es un PDF.
 */
export function useAttachmentViewer(): {
    open: (items: ViewerItem[], index?: number) => void;
    viewer: ReactNode;
} {
    const [abierto, setAbierto] = useState<{
        items: ViewerItem[];
        index: number;
        /** Lo que tenía el foco al abrir: la tarjeta, la miniatura, el enlace. */
        origen: HTMLElement | null;
    } | null>(null);

    /*
     * Al cerrar, el foco vuelve a lo que abrió el visor.
     *
     * Radix lo devuelve a lo que tenía el foco cuando el diálogo se montó,
     * pero el visor se monta tarde —se descarga al abrirlo— y se desmonta
     * al cerrar, así que no hay garantía de que siga siendo la tarjeta. Sin
     * esto, quien usa el teclado perdía su lugar en la galería. Va en el
     * cuadro siguiente para quedar después del foco que pone Radix.
     */
    const cerrar = () => {
        const origen = abierto?.origen ?? null;
        setAbierto(null);

        if (origen !== null) {
            requestAnimationFrame(() => {
                if (origen.isConnected) {
                    origen.focus();
                }
            });
        }
    };

    return {
        open: (items, index = 0) => {
            if (items.length > 0) {
                const activo = document.activeElement;
                setAbierto({
                    items,
                    index,
                    origen: activo instanceof HTMLElement ? activo : null,
                });
            }
        },
        viewer:
            abierto === null ? null : (
                <Suspense fallback={null}>
                    <AttachmentViewer
                        items={abierto.items}
                        initialIndex={abierto.index}
                        onClose={cerrar}
                    />
                </Suspense>
            ),
    };
}
