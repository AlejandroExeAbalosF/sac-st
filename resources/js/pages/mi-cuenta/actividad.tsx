import { Head, router } from '@inertiajs/react';
import { History, MonitorSmartphone } from 'lucide-react';
import PageHeader from '@/components/page-header';
import type { PaginationData } from '@/components/pagination-footer';
import PaginationFooter from '@/components/pagination-footer';
import { Button } from '@/components/ui/button';
import AccessTable from '@/features/cuenta/components/access-table';
import AccountNav from '@/features/cuenta/components/account-nav';
import SessionList from '@/features/cuenta/components/session-list';
import { destroy as cerrarSesiones } from '@/routes/mi-cuenta/sesiones';

type Sesion = App.Modules.Shared.Data.ActiveSessionData;
type Evento = App.Modules.Shared.Data.AccessEventData;

type Props = {
    sessions: Sesion[];
    events: { data: Evento[] } & PaginationData;
};

/**
 * Qué pasó con esta cuenta.
 *
 * Es la pantalla que le permite al titular ver lo que nadie más puede
 * confirmar por él: si ese ingreso del sábado a las 23:10 fue suyo. Los
 * datos se venían escribiendo en `user_login_events` desde el primer día,
 * append-only y con trigger; hasta ahora solo se asomaban en las últimas
 * cinco filas del tablero.
 */
export default function Actividad({ sessions, events }: Props) {
    const otras = sessions.filter((session) => !session.isCurrent).length;

    const cerrarLasDemas = () =>
        router.delete(cerrarSesiones().url, { preserveScroll: true });

    return (
        <>
            <Head title="Actividad de mi cuenta" />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Mi cuenta"
                    title="Actividad"
                    description="Dónde está abierta tu sesión y qué pasó con tu usuario."
                />

                <AccountNav />

                <section className="rounded-lg border bg-card shadow-raised">
                    <header className="flex flex-wrap items-center gap-3 border-b px-5 py-3.5">
                        <h2 className="flex items-center gap-2 text-sm font-semibold">
                            <MonitorSmartphone
                                className="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            Sesiones abiertas
                        </h2>

                        {otras > 0 && (
                            <Button
                                variant="outline"
                                size="sm"
                                className="ml-auto"
                                onClick={cerrarLasDemas}
                                data-test="close-other-sessions"
                            >
                                Cerrar las demás ({otras})
                            </Button>
                        )}
                    </header>

                    <SessionList
                        sessions={sessions}
                        emptyLabel="No hay ninguna sesión abierta con esta cuenta."
                    />
                </section>

                <section className="rounded-lg border bg-card shadow-raised">
                    <header className="border-b px-5 py-3.5">
                        <h2 className="flex items-center gap-2 text-sm font-semibold">
                            <History
                                className="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            Historial de accesos
                        </h2>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Si ves un ingreso que no reconocés, cambiá la
                            contraseña y avisá al administrador.
                        </p>
                    </header>

                    <AccessTable
                        events={events.data}
                        emptyLabel="Todavía no hay actividad registrada en esta cuenta."
                    />
                </section>

                <PaginationFooter
                    pagination={events}
                    singular="evento"
                    plural="eventos"
                />
            </div>
        </>
    );
}
