import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { AppLayoutProps } from '@/types';

/**
 * El layout ya no recibe el camino de migas: llega solo, como prop compartida
 * desde `HandleInertiaRequests`. Ver `components/breadcrumbs.tsx`.
 */
export default function AppLayout({ children }: AppLayoutProps) {
    return <AppLayoutTemplate>{children}</AppLayoutTemplate>;
}
