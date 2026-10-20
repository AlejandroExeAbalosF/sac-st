import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { UserMenu } from '@/components/user-menu';

export function AppSidebarHeader() {
    /*
     * Blanco como las tarjetas y apoyado sobre el fondo gris igual que ellas:
     * el encabezado deja de ser una franja del mismo color que la página con
     * una línea encima, y pasa a ser una superficie.
     *
     * Fijo, y no por gusto: acá vive la única referencia de dónde está parado
     * el operador dentro del circuito. En el detalle de un haber o de un
     * expediente —pantallas de varias vueltas de scroll— una referencia que se
     * va con el contenido no es una referencia.
     *
     * `z-10` tampoco es decorativo: sin él, el fondo de la primera sección de
     * cada pantalla se dibuja encima y se come la sombra.
     */
    return (
        <header className="sticky top-0 z-10 flex h-16 shrink-0 items-center gap-2 border-b border-border/70 bg-card px-6 shadow-raised transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            {/*
             * `flex-1` y `contain: inline-size`: el camino ocupa lo que queda y
             * no aporta su ancho al mínimo de la pantalla. Sin esto, un último
             * eslabón largo —una carátula, un beneficiario— se recortaba a la
             * vista pero empujaba igual el ancho de toda la columna, y la
             * página se desplazaba de costado.
             */}
            <div className="flex min-w-0 flex-1 items-center gap-2 [contain:inline-size]">
                <SidebarTrigger className="-ml-1 shrink-0" />
                <Breadcrumbs />
            </div>

            {/* Todo lo que sea de la sesión —y no de la página— va a la derecha. */}
            <div className="ml-auto flex shrink-0 items-center gap-2">
                <UserMenu />
            </div>
        </header>
    );
}
