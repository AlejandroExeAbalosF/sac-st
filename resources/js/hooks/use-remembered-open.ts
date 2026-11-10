import { useCallback, useSyncExternalStore } from 'react';

/**
 * Qué filas de un listado dejó desplegadas el usuario.
 *
 * Se recuerda por pestaña del navegador (`sessionStorage`): ir al detalle
 * de un expediente y volver —con «atrás» o desde la barra— encuentra los
 * acordeones como se dejaron, y una pestaña nueva arranca con todo
 * cerrado. La copia en memoria es la que manda mientras la aplicación
 * está viva; el almacenamiento solo la trae de vuelta tras un refresco, y
 * si el navegador lo bloquea los acordeones igual funcionan.
 *
 * El servidor dibuja todo cerrado: en una navegación dentro de la
 * aplicación no hay render del servidor y las filas abren ya desplegadas;
 * solo un refresco completo las muestra cerradas un instante.
 */

/** Tope de filas recordadas por listado: las más viejas se olvidan. */
export const MAX_REMEMBERED = 100;

const PREFIX = 'filas-abiertas:';

const memory = new Map<string, number[]>();
const listeners = new Set<() => void>();

/** La lista con `id` agregado o quitado, sin repetir y con tope. */
export function toggledIds(ids: number[], id: number): number[] {
    return ids.includes(id)
        ? ids.filter((actual) => actual !== id)
        : [...ids, id].slice(-MAX_REMEMBERED);
}

/** Lo guardado, si es una lista de ids; cualquier otra cosa es nada. */
export function parseIds(raw: string | null): number[] {
    if (raw === null) {
        return [];
    }

    try {
        const parsed: unknown = JSON.parse(raw);

        return Array.isArray(parsed)
            ? parsed.filter((valor): valor is number => Number.isInteger(valor))
            : [];
    } catch {
        return [];
    }
}

function read(scope: string): number[] {
    let ids = memory.get(scope);

    if (ids === undefined) {
        try {
            ids = parseIds(window.sessionStorage.getItem(PREFIX + scope));
        } catch {
            ids = [];
        }

        memory.set(scope, ids);
    }

    return ids;
}

function write(scope: string, ids: number[]): void {
    memory.set(scope, ids);

    try {
        window.sessionStorage.setItem(PREFIX + scope, JSON.stringify(ids));
    } catch {
        // Sin almacenamiento se recuerda solo mientras la página siga viva.
    }

    listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

/**
 * Si la fila `id` del listado `scope` está abierta, y cómo alternarla.
 *
 * La fila de escritorio y la tarjeta del teléfono comparten el estado:
 * son la misma fila dibujada de dos formas.
 */
export function useRememberedOpen(
    scope: string,
    id: number,
): [boolean, () => void] {
    const open = useSyncExternalStore(
        subscribe,
        () => read(scope).includes(id),
        () => false,
    );

    const toggle = useCallback(
        () => write(scope, toggledIds(read(scope), id)),
        [scope, id],
    );

    return [open, toggle];
}
