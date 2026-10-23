import { toUrl } from '@/lib/utils';
import type { NavLink } from '@/types';

/**
 * Cuál de las pantallas hermanas de un acordeón es la actual, si alguna.
 *
 * Una dirección cuelga de otra si la repite entera y sigue después de una
 * barra: `/multas/caja/dia` cuelga de `/multas/caja`. Cuando cuelga de más
 * de una hermana gana la más larga, que es la más específica. Sin esa regla,
 * parado en la caja del día de Multas se marcaban tres a la vez: la caja del
 * día, la Caja de Multas (`/multas/caja`) y el listado, cuando vivía en
 * `/multas`.
 *
 * Antes que la dirección cuenta la pantalla: una hermana puede declarar en
 * `activeOn` los componentes de Inertia que también son suyos aunque su
 * dirección no cuelgue de la de ella, como la ficha de una multa
 * (`/multas/{id}`) respecto del listado (`/multas/expedientes`).
 */
export function activeChild(
    children: NavLink[],
    currentPath: string,
    component: string,
): NavLink | undefined {
    const porPantalla = children.find((child) =>
        child.activeOn?.includes(component),
    );

    if (porPantalla !== undefined) {
        return porPantalla;
    }

    let elegida: NavLink | undefined;
    let largo = -1;

    for (const child of children) {
        const base = pathOf(child.href);

        if (cuelgaDe(currentPath, base) && base.length > largo) {
            elegida = child;
            largo = base.length;
        }
    }

    return elegida;
}

function pathOf(href: NavLink['href']): string {
    return new URL(toUrl(href), 'http://localhost').pathname;
}

function cuelgaDe(path: string, base: string): boolean {
    return path === base || path.startsWith(`${base}/`);
}
