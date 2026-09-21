import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

/**
 * Un eslabón del camino de migas, tal como lo manda el servidor.
 *
 * Los arma `routes/breadcrumbs.php` y llegan como prop compartida: el front no
 * los declara ni los completa. `href` viene en `null` cuando el eslabón nombra
 * una sección y no una pantalla —«Configuración», por ejemplo—, y también en el
 * último, que es donde ya se está parado.
 */
export type BreadcrumbItem = {
    title: string;
    href: string | null;
};

export type NavItem = {
    title: string;
    /** Opcional: un ítem todavía no construido no tiene a dónde ir. */
    href?: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
    /**
     * Marca un módulo previsto pero todavía no disponible. Se muestra
     * apagado y sin enlace, con el motivo en el tooltip: el área conoce
     * el alcance completo del sistema y ocultar los módulos que faltan
     * haría pensar que se descartaron.
     */
    disabled?: boolean;
    /** Texto del tooltip cuando el ítem está deshabilitado. */
    disabledReason?: string;
    /** Contador de trabajo pendiente, a la derecha del ítem. */
    badge?: number;
    /**
     * Permiso que hace falta para ver el ítem. Sin permiso no se dibuja
     * apagado: desaparece. Un ítem apagado anuncia algo que va a existir;
     * una pantalla que el usuario no puede abrir no es eso, y ofrecerla
     * solo lleva a un 403.
     */
    permission?: string;
    /**
     * Sub-pantallas que cuelgan de este ítem. Si están, el ítem se dibuja
     * como acordeón: el título despliega la lista en vez de —o además de—
     * navegar. Es para un módulo con varias pantallas hermanas, como Caja.
     */
    children?: NavLink[];
};

/**
 * Un ítem de navegación que siempre lleva destino. Se usa donde el menú
 * no admite entradas deshabilitadas, para no tener que comprobar `href`
 * en cada uso.
 */
export type NavLink = NavItem & {
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavGroup = {
    /** Encabezado del grupo. Se oculta con la barra colapsada. */
    label: string;
    items: NavItem[];
};
