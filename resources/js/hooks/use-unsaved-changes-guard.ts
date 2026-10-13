import type { PendingVisit } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

type Guardia = {
    /** La navegación que quedó frenada, esperando que se confirme o se cancele. */
    pendiente: PendingVisit | null;
    /** Descarta los cambios y sigue a donde se iba. */
    continuar: () => void;
    /** Se queda en la pantalla. */
    cancelar: () => void;
    /**
     * Deja pasar las visitas que dispare `visita`, como el propio guardado.
     *
     * Funciona porque Inertia emite `before` dentro de la misma llamada a
     * `router.visit`, antes de devolver: la excepción dura exactamente eso.
     */
    sinGuardia: (visita: () => void) => void;
};

/**
 * Frena la salida de una pantalla que tiene cambios sin guardar.
 *
 * Cubre las dos salidas que se pueden interceptar: una navegación de
 * Inertia —el menú, un enlace, cerrar sesión—, que queda pendiente hasta
 * que alguien decida, y cerrar o recargar la pestaña, donde el navegador
 * muestra su propia pregunta.
 *
 * No frena los prefetch del menú, que se disparan al pasar el mouse y no
 * sacan a nadie de la pantalla, ni las recargas parciales, que conservan
 * el estado.
 */
export function useUnsavedChangesGuard(dirty: boolean): Guardia {
    const [pendiente, setPendiente] = useState<PendingVisit | null>(null);
    const permitir = useRef(false);

    useEffect(() => {
        if (!dirty) {
            return;
        }

        const quitarBefore = router.on('before', (event) => {
            const visit = event.detail.visit;

            if (
                permitir.current ||
                visit.prefetch ||
                visit.only.length > 0 ||
                visit.except.length > 0
            ) {
                return;
            }

            event.preventDefault();
            setPendiente(visit);
        });

        const alCerrarPestana = (event: BeforeUnloadEvent) => {
            event.preventDefault();
        };

        window.addEventListener('beforeunload', alCerrarPestana);

        return () => {
            quitarBefore();
            window.removeEventListener('beforeunload', alCerrarPestana);
        };
    }, [dirty]);

    const sinGuardia = useCallback((visita: () => void) => {
        permitir.current = true;

        try {
            visita();
        } finally {
            permitir.current = false;
        }
    }, []);

    const continuar = useCallback(() => {
        if (pendiente === null) {
            return;
        }

        setPendiente(null);
        sinGuardia(() =>
            router.visit(pendiente.url, {
                method: pendiente.method,
                data: pendiente.data,
                replace: pendiente.replace,
                preserveScroll: pendiente.preserveScroll,
                preserveState: pendiente.preserveState,
                headers: pendiente.headers,
            }),
        );
    }, [pendiente, sinGuardia]);

    const cancelar = useCallback(() => setPendiente(null), []);

    return { pendiente, continuar, cancelar, sinGuardia };
}
