import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import ClosedPeriodDialog from '@/components/closed-period-dialog';
import MissingOpeningDialog from '@/components/missing-opening-dialog';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({ children }: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar">
                <AppSidebarHeader />
                {/*
                 * El recorte horizontal va acá y no en el contenedor de
                 * afuera: `overflow-x` distinto de `visible` convierte al
                 * elemento en contenedor de scroll, y el encabezado fijo
                 * dejaría de pegarse a la ventana. Envolviendo solo a la
                 * página, las tablas anchas siguen sin desbordar y el
                 * encabezado sigue arriba.
                 *
                 * Y es `clip`, no `hidden`, por la misma razón un nivel más
                 * abajo: `hidden` convertía a este div en el contenedor de
                 * scroll de todo lo `sticky` de las páginas —el panel de la
                 * foto del ticket, la barra de guardado de Roles— y como el
                 * div nunca se desplaza, nada se quedaba pegado. `clip`
                 * recorta igual sin crear un contenedor de scroll.
                 */}
                <div className="flex min-w-0 flex-1 flex-col overflow-x-clip">
                    {children}
                </div>

                {/*
                 * Cualquier operación que mueva dinero puede toparse con un
                 * período cerrado, así que la explicación vive acá y no en
                 * cada pantalla. Lo mismo un libro sin apertura.
                 */}
                <ClosedPeriodDialog />
                <MissingOpeningDialog />
            </AppContent>
        </AppShell>
    );
}
