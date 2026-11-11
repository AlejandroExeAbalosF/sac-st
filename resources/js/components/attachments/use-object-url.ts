import { useEffect, useMemo } from 'react';

/**
 * Una URL local para un archivo del navegador, liberada al cambiarlo o al
 * desmontar: sin eso cada archivo elegido queda retenido en memoria hasta
 * recargar.
 */
export function useObjectUrl(blob: Blob | null): string | null {
    const url = useMemo(
        () => (blob === null ? null : URL.createObjectURL(blob)),
        [blob],
    );

    useEffect(() => {
        if (url === null) {
            return;
        }

        return () => URL.revokeObjectURL(url);
    }, [url]);

    return url;
}
