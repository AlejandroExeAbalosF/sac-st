/**
 * Qué acordeones de la barra dejó abiertos el usuario, para que sigan así al
 * recargar. Es una preferencia del puesto, no de la cuenta.
 *
 * Va en una cookie y no en `localStorage` por el renderizado del servidor:
 * el servidor la lee y la comparte como `sidebarSections`
 * (`HandleInertiaRequests`), así dibuja la barra igual que el navegador. Con
 * `localStorage` el servidor la dibujaba cerrada, el navegador abierta, y
 * React rehacía el árbol por la diferencia. Es el mismo camino que sigue
 * `sidebar_state` para el colapso de la barra entera.
 */
export const SECTIONS_COOKIE = 'sidebar_sections';

const MAX_AGE = 60 * 60 * 24 * 365;

/** Las secciones guardadas en una cadena de cookies como `document.cookie`. */
export function parseOpenSections(cookies: string): string[] {
    const raw = cookies
        .split('; ')
        .find((cookie) => cookie.startsWith(`${SECTIONS_COOKIE}=`))
        ?.slice(SECTIONS_COOKIE.length + 1);

    if (raw === undefined) {
        return [];
    }

    try {
        const parsed: unknown = JSON.parse(decodeURIComponent(raw));

        return Array.isArray(parsed)
            ? parsed.filter(
                  (title): title is string => typeof title === 'string',
              )
            : [];
    } catch {
        return [];
    }
}

export function withSection(
    sections: string[],
    title: string,
    open: boolean,
): string[] {
    const others = sections.filter((t) => t !== title);

    return open ? [...others, title] : others;
}

/**
 * Parte de lo que dice la cookie y no de un estado de React: cada acordeón
 * lleva el suyo, y la cookie es lo único que los ve a todos.
 */
export function rememberSection(title: string, open: boolean): void {
    const next = withSection(parseOpenSections(document.cookie), title, open);

    document.cookie = `${SECTIONS_COOKIE}=${encodeURIComponent(JSON.stringify(next))}; path=/; max-age=${MAX_AGE}; samesite=lax`;
}
