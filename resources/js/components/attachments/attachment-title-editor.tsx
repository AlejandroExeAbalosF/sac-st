import { router } from '@inertiajs/react';
import { useState } from 'react';
import { cn } from '@/lib/utils';
import { rename as renameRoute } from '@/routes/adjuntos';

/** El mismo tope que al subir el archivo (`StoreAttachment`). */
export const ATTACHMENT_TITLE_MAX = 200;

/**
 * Pide corregir la descripción de un adjunto.
 *
 * Vuelve a la misma pantalla con los datos nuevos (`back()` del servidor),
 * sin mover el scroll. El error de validación, si lo hay, llega en
 * `onError` para mostrarlo al lado del campo.
 */
export function renameAttachment(
    id: number,
    title: string,
    callbacks: { onSuccess?: () => void; onError?: (message: string) => void },
): void {
    router.patch(
        renameRoute(id).url,
        { title },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => callbacks.onSuccess?.(),
            onError: (errors) =>
                callbacks.onError?.(
                    errors.title ?? 'No se pudo corregir la descripción.',
                ),
        },
    );
}

/**
 * El campo para corregir la descripción en el lugar.
 *
 * Enter guarda y Escape cancela. Escape no cierra el diálogo que lo
 * contenga: el campo va marcado con `data-local-escape`, y el visor lo
 * respeta.
 */
export function AttachmentTitleEditor({
    initial,
    tone = 'light',
    onDone,
    error,
}: {
    initial: string;
    /** `dark` sobre la barra del visor o el degradado de la tarjeta. */
    tone?: 'light' | 'dark';
    /** El valor nuevo, o `null` si se canceló o quedó igual. */
    onDone: (value: string | null) => void;
    error?: string | null;
}) {
    const [value, setValue] = useState(initial);

    const confirmar = () => {
        const limpio = value.trim();
        onDone(limpio !== '' && limpio !== initial ? limpio : null);
    };

    return (
        <div className="grid gap-1">
            <input
                autoFocus
                value={value}
                maxLength={ATTACHMENT_TITLE_MAX}
                aria-label="Descripción del archivo"
                aria-invalid={Boolean(error)}
                data-local-escape
                onFocus={(e) => e.currentTarget.select()}
                onChange={(e) => setValue(e.target.value)}
                onBlur={confirmar}
                onKeyDown={(e) => {
                    // Que no lo tomen el visor (flechas, zoom) ni la tarjeta.
                    e.stopPropagation();

                    if (e.key === 'Enter') {
                        e.preventDefault();
                        confirmar();
                    } else if (e.key === 'Escape') {
                        e.preventDefault();
                        onDone(null);
                    }
                }}
                className={cn(
                    'pointer-events-auto w-full min-w-0 rounded-md border px-2 py-1 text-sm outline-none focus-visible:ring-2',
                    tone === 'dark'
                        ? 'border-white/40 bg-black/60 text-white focus-visible:ring-white/60'
                        : 'border-primary bg-background text-foreground focus-visible:ring-ring',
                )}
            />
            {error && (
                <p
                    className={cn(
                        'text-xs',
                        tone === 'dark'
                            ? 'text-white'
                            : 'text-destructive-strong',
                    )}
                    role="alert"
                >
                    {error}
                </p>
            )}
        </div>
    );
}
