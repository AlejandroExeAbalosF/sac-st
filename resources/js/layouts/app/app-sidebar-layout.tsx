import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import ClosedPeriodDialog from '@/components/closed-period-dialog';
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
                 */}
                <div className="flex min-w-0 flex-1 flex-col overflow-x-hidden">
                    {children}
                </div>

                {/*
                 * Cualquier operación que mueva dinero puede toparse con un
                 * período cerrado, así que la explicación vive acá y no en
                 * cada pantalla.
                 */}
                <ClosedPeriodDialog />
            </AppContent>
        </AppShell>
    );
}
