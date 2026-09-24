import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { DrawerProvider } from '@/features/drawer/drawer-context';
import DrawerHost from '@/features/drawer/drawer-host';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import { applyCspNonce } from '@/lib/csp-nonce';
import type { Auth } from '@/types';

// Vite lo fija al compilar, y la imagen de producción se compila sin `.env`:
// sin este valor, las pestañas del navegador decían «Laravel».
const appName = import.meta.env.VITE_APP_NAME || 'SAC-ST';

// Antes de montar: los diálogos de Radix lo leen al abrirse.
const nonce = applyCspNonce();

createInertiaApp({
    // La barra de progreso inyecta su `<style>`: sin nonce, la CSP lo bloquea.
    nonce,
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name, page) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            /*
             * Una pantalla de error la puede ver alguien sin sesión —un 404
             * en una dirección pública—, y ahí `AppLayout` no tiene barra
             * lateral que dibujar ni permisos que leer. Con sesión, en
             * cambio, el sentido de la pantalla es justamente que el
             * operador no salga del sistema.
             */
            case name.startsWith('errors/'): {
                const { auth } = page.props as { auth?: Auth };

                return auth?.user ? AppLayout : null;
            }
            /*
             * «Mi cuenta» y «Configuración» ya no tienen layout propio: son
             * pantallas normales, con el mismo encabezado y el mismo ancho
             * que Caja o Personas. El nav de secciones de la cuenta vive
             * adentro de cada pantalla (features/cuenta/account-nav).
             */
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    /*
     * Lo de acá adentro envuelve a la aplicación por fuera del switch de
     * layouts, así que no se remonta al navegar: es lo que hace que un
     * toast sobreviva a la redirección que lo produjo, y lo que permite
     * que el panel lateral siga abierto mientras la pantalla de atrás
     * cambia.
     */
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                <DrawerProvider>
                    {app}
                    <DrawerHost />
                </DrawerProvider>
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
