import type { ReactNode } from 'react';

export type AppLayoutProps = {
    children: ReactNode;
};

export type AppVariant = 'header' | 'sidebar';

/**
 * Una confirmación efímera, tal como la manda `App\Support\Ui\Toast`.
 *
 * `message` es qué pasó y `description` qué hacer con eso; el segundo es
 * opcional porque los mensajes de una sola oración —los que todavía llegan
 * por el puente de `status`— no tienen dónde partirse.
 */
export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
    description?: string;
};

export type AuthLayoutProps = {
    children?: ReactNode;
    name?: string;
    title?: string;
    description?: string;
};
