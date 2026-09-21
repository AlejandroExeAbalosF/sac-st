import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import type { FlashToast } from '@/types/ui';

const TOAST_TYPES: FlashToast['type'][] = [
    'success',
    'info',
    'warning',
    'error',
];

const TOAST_DURATION: Record<FlashToast['type'], number> = {
    success: 6000,
    info: 7000,
    warning: 9000,
    // Un rechazo puede requerir leer el motivo antes de corregir la acción.
    // No mueve el contenido y se puede cerrar, pero no desaparece solo.
    error: Infinity,
};

function isFlashToast(value: unknown): value is FlashToast {
    if (!value || typeof value !== 'object') {
        return false;
    }

    const candidate = value as Partial<FlashToast>;

    return (
        typeof candidate.message === 'string' &&
        candidate.message.trim().length > 0 &&
        (candidate.description === undefined ||
            typeof candidate.description === 'string') &&
        TOAST_TYPES.includes(candidate.type as FlashToast['type'])
    );
}

export function useFlashToast(): void {
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const data: unknown = flash?.toast;

            if (!isFlashToast(data)) {
                return;
            }

            const description = data.description?.trim();

            toast[data.type](data.message.trim(), {
                duration: TOAST_DURATION[data.type],
                description:
                    description !== undefined && description !== ''
                        ? description
                        : undefined,
            });
        });
    }, []);
}
