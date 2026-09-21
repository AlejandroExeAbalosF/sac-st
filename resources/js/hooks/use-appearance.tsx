import { useSyncExternalStore } from 'react';

export type ResolvedAppearance = 'light' | 'dark';
export type Appearance = ResolvedAppearance | 'system';

export type UseAppearanceReturn = {
    readonly appearance: Appearance;
    readonly resolvedAppearance: ResolvedAppearance;
    readonly updateAppearance: (mode: Appearance) => void;
};

/**
 * El modo oscuro está apagado hasta que reciba su pasada de diseño.
 *
 * Los tokens de `.dark` ya existen en `resources/css/app.css` y tienen el
 * contraste verificado, pero la jerarquía visual no está trabajada. Dejar
 * un interruptor que entrega una pantalla a medio hacer es peor que no
 * ofrecerlo, así que el sistema se muestra siempre en claro —sin importar
 * lo que el usuario haya elegido antes ni cómo tenga el sistema operativo.
 *
 * Para reactivarlo cuando esté listo:
 *   1. `DARK_MODE_ENABLED = true` acá;
 *   2. reponer el ítem "Apariencia" en `layouts/settings/layout.tsx`;
 *   3. reponer la ruta `appearance.edit` en `routes/settings.php`;
 *   4. devolverle a `HandleAppearance` la lectura de la cookie.
 */
const DARK_MODE_ENABLED = false;

const listeners = new Set<() => void>();

const DEFAULT_APPEARANCE: Appearance = 'light';

let currentAppearance: Appearance = DEFAULT_APPEARANCE;

const prefersDark = (): boolean => {
    if (typeof window === 'undefined') {
        return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
};

const setCookie = (name: string, value: string, days = 365): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;
    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const getStoredAppearance = (): Appearance => {
    if (!DARK_MODE_ENABLED || typeof window === 'undefined') {
        return DEFAULT_APPEARANCE;
    }

    return (
        (localStorage.getItem('appearance') as Appearance) || DEFAULT_APPEARANCE
    );
};

const isDarkMode = (appearance: Appearance): boolean => {
    if (!DARK_MODE_ENABLED) {
        return false;
    }

    return appearance === 'dark' || (appearance === 'system' && prefersDark());
};

const applyTheme = (appearance: Appearance): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const isDark = isDarkMode(appearance);

    document.documentElement.classList.toggle('dark', isDark);
    document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
};

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => listeners.delete(callback);
};

const notify = (): void => listeners.forEach((listener) => listener());

const mediaQuery = (): MediaQueryList | null => {
    if (typeof window === 'undefined') {
        return null;
    }

    return window.matchMedia('(prefers-color-scheme: dark)');
};

const handleSystemThemeChange = (): void => applyTheme(currentAppearance);

export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (!DARK_MODE_ENABLED) {
        // Se limpia lo que hubiera guardado de antes: si alguien probó el
        // modo oscuro mientras estuvo disponible, su elección no puede
        // seguir aplicándose ahora que está apagado.
        localStorage.removeItem('appearance');
        setCookie('appearance', DEFAULT_APPEARANCE);

        currentAppearance = DEFAULT_APPEARANCE;
        applyTheme(DEFAULT_APPEARANCE);

        return;
    }

    if (!localStorage.getItem('appearance')) {
        localStorage.setItem('appearance', DEFAULT_APPEARANCE);
        setCookie('appearance', DEFAULT_APPEARANCE);
    }

    currentAppearance = getStoredAppearance();
    applyTheme(currentAppearance);

    mediaQuery()?.addEventListener('change', handleSystemThemeChange);
}

export function useAppearance(): UseAppearanceReturn {
    const appearance: Appearance = useSyncExternalStore(
        subscribe,
        () => currentAppearance,
        () => DEFAULT_APPEARANCE,
    );

    const resolvedAppearance: ResolvedAppearance = isDarkMode(appearance)
        ? 'dark'
        : 'light';

    const updateAppearance = (mode: Appearance): void => {
        if (!DARK_MODE_ENABLED) {
            return;
        }

        currentAppearance = mode;

        // Store in localStorage for client-side persistence...
        localStorage.setItem('appearance', mode);

        // Store in cookie for SSR...
        setCookie('appearance', mode);

        applyTheme(mode);
        notify();
    };

    return { appearance, resolvedAppearance, updateAppearance };
}
