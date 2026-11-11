import { useCallback, useState } from 'react';

/**
 * Se vuelve `true` la primera vez que el elemento entra en pantalla, y ahí
 * queda.
 *
 * Es lo que hace que una lista de veinte adjuntos no pida veinte archivos
 * al abrirse: cada miniatura se carga cuando llega a verse.
 */
export function useInViewOnce<T extends Element>(rootMargin = '200px') {
    const [inView, setInView] = useState(false);

    const ref = useCallback(
        (node: T | null) => {
            if (node === null || inView) {
                return;
            }

            if (typeof IntersectionObserver === 'undefined') {
                setInView(true);

                return;
            }

            const observer = new IntersectionObserver(
                (entries) => {
                    if (entries.some((entry) => entry.isIntersecting)) {
                        setInView(true);
                        observer.disconnect();
                    }
                },
                { rootMargin },
            );
            observer.observe(node);

            return () => observer.disconnect();
        },
        [inView, rootMargin],
    );

    return { ref, inView };
}
