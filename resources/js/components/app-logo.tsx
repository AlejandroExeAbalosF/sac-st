import AppLogoIcon from '@/components/app-logo-icon';

/**
 * Identificación del sistema en la barra lateral: sigla arriba, área
 * abajo. Es la jerarquía del diseño del pasante — "SAC" grande y
 * "ÁREA CONTABLE" como bajada en versalitas.
 *
 * El isotipo va suelto sobre el azul de la barra, sin placa: lo despega el
 * contorno blanco de `halo` (ver AppLogoIcon). A 32 px ese contorno rescata
 * la silueta, no las caras internas de la caja — el volumen se lee menos que
 * sobre la placa; es el precio de que la marca no arrastre un recuadro.
 *
 * El isotipo sigue envuelto en un `div` aunque ya no haya placa que dibujar:
 * `SidebarMenuButton` trae `[&>svg]:size-4`, así que un SVG hijo directo del
 * botón quedaría a 16 px. El `div` lo deja fuera de ese selector.
 *
 * Plegada, la barra reduce el botón a 32 px con `overflow-hidden` y el halo
 * quedaría recortado contra el borde; por eso ahí el isotipo baja a 28 px y
 * se deja el aire que el resplandor necesita.
 *
 * La entrada se anima una sola vez, al cargar la página: la barra lateral
 * no se remonta al navegar, así que el movimiento no se repite en cada
 * pantalla.
 */
export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 shrink-0 items-center justify-center motion-safe:animate-in motion-safe:duration-500 motion-safe:zoom-in-90 motion-safe:fade-in">
                {/* Decorativo: la sigla va escrita al lado, en texto. */}
                <AppLogoIcon
                    halo
                    className="size-8 group-data-[collapsible=icon]:size-7"
                    aria-hidden="true"
                />
            </div>
            <div className="ml-1 grid flex-1 text-left leading-tight">
                <span className="truncate text-sm font-bold tracking-wide">
                    SAC
                </span>
                {/* En el acento de marca, el mismo que firma el membrete
                    de la pantalla de acceso: las dos son la bajada
                    institucional bajo la marca. Medido, 4.89:1 contra el
                    azul de la barra — pasa AA. Antes iba en blanco al 70 %
                    (5.39:1); se cede un poco de contraste, pero no se baja
                    del mínimo. */}
                <span className="truncate text-[10px] tracking-[0.14em] text-sidebar-brand uppercase">
                    Área contable
                </span>
            </div>
        </>
    );
}
