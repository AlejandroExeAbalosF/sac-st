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
    } | null>(null);

    return {
        open: (items, index = 0) => {
            if (items.length > 0) {
                setAbierto({ items, index });
            }
        },
        viewer:
            abierto === null ? null : (
                <Suspense fallback={null}>
                    <AttachmentViewer
                        items={abierto.items}
                        initialIndex={abierto.index}
                        onClose={() => setAbierto(null)}
                    />
                </Suspense>
            ),
    };
}
