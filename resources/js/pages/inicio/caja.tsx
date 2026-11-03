import { Deferred, Head, Link } from '@inertiajs/react';
import { ArrowRight, FilePlus2, FileSpreadsheet } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import GlobalSearch from '@/components/global-search';
import PageHeader from '@/components/page-header';
import CashDayCard from '@/features/inicio/components/cash-day-card';
import QueueCard from '@/features/inicio/components/queue-card';
import QueueList from '@/features/inicio/components/queue-list';
import RecentAccessList from '@/features/inicio/components/recent-access';
import RecentMovements from '@/features/inicio/components/recent-movements';
import SetupChecklist from '@/features/inicio/components/setup-checklist';
import {
    BlockUnavailable,
    CashDaySkeleton,
    ListSkeleton,
} from '@/features/inicio/components/skeletons';
import { date } from '@/lib/format';
import { create as importarExtracto } from '@/routes/banco/extractos';
import { create as nuevoExpediente } from '@/routes/expedientes';
import { create as nuevaMulta } from '@/routes/multas';

type Props = {
    box: { code: 'haberes' | 'multas'; name: string };
    operationalDate: string;
    setup: App.Modules.Shared.Data.SetupStepData[];
    recentAccess: App.Modules.Shared.Data.RecentAccessData[];
    movements?: App.Modules.Ledger.Data.MovementRowData[];
    can: {
        verCaja: boolean;
        verExpedientes: boolean;
        crearExpediente: boolean;
        importarExtracto: boolean;
    };
    queues?: App.Modules.Shared.Data.WorkQueueData[];
    cashDay?: App.Modules.Ledger.Data.CashDayStatusData | null;
};

export default function CashBoxHome({
    box,
    operationalDate,
    can,
    queues,
    cashDay,
    setup,
    recentAccess,
    movements,
}: Props) {
    return (
        <>
            <Head title={`Inicio de ${box.name}`} />
            <div className="flex w-full flex-col gap-8 p-4 sm:p-6">
                <PageHeader
                    title={box.name}
                    eyebrow={`Fecha operativa · ${date(operationalDate)}`}
                    description="Estado de la caja, trabajo pendiente y acciones habituales."
                />

                {setup.length > 0 && <SetupChecklist steps={setup} />}
                {box.code === 'haberes' && can.verExpedientes && (
                    <GlobalSearch />
                )}
                {box.code === 'multas' && can.verExpedientes && (
                    <Link
                        href="/multas/expedientes"
                        className="text-sm font-medium text-primary hover:underline"
                    >
                        Buscar expedientes y multas →
                    </Link>
                )}

                {can.verCaja && (
                    <Deferred
                        data="cashDay"
                        fallback={<CashDaySkeleton />}
                        rescue={
                            <BlockUnavailable what="el estado de la caja" />
                        }
                    >
                        {cashDay ? <CashDayCard day={cashDay} /> : <></>}
                    </Deferred>
                )}

                <section aria-labelledby="pendientes" className="grid gap-4">
                    <div>
                        <h2 id="pendientes" className="text-lg font-semibold">
                            Requiere atención
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Sólo se muestran tareas con casos pendientes.
                        </p>
                    </div>
                    <Deferred
                        data="queues"
                        fallback={<ListSkeleton rows={4} />}
                        rescue={
                            <BlockUnavailable what="el trabajo pendiente" />
                        }
                    >
                        <QueueList queues={queues ?? []} />
                    </Deferred>
                </section>

                <QuickStarts box={box.code} can={can} />
                <section
                    aria-labelledby="circuito-completo"
                    className="grid gap-4"
                >
                    <h2
                        id="circuito-completo"
                        className="text-lg font-semibold"
                    >
                        Estado del circuito
                    </h2>
                    <Deferred
                        data="queues"
                        fallback={<ListSkeleton rows={3} />}
                        rescue={<BlockUnavailable what="el circuito" />}
                    >
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            {(queues ?? []).map((queue) => (
                                <QueueCard key={queue.key} queue={queue} />
                            ))}
                        </div>
                    </Deferred>
                </section>
                <div className="grid gap-6 lg:grid-cols-2">
                    {can.verCaja && (
                        <section className="overflow-hidden rounded-lg border bg-card">
                            <h2 className="border-b px-5 py-4 text-sm font-semibold">
                                Movimientos recientes de {box.name}
                            </h2>
                            <Deferred
                                data="movements"
                                fallback={<ListSkeleton />}
                                rescue={
                                    <BlockUnavailable what="los movimientos" />
                                }
                            >
                                <RecentMovements movements={movements ?? []} />
                            </Deferred>
                        </section>
                    )}
                    <section className="overflow-hidden rounded-lg border bg-card">
                        <h2 className="border-b px-5 py-4 text-sm font-semibold">
                            Accesos recientes a tu cuenta
                        </h2>
                        <RecentAccessList events={recentAccess} />
                    </section>
                </div>
            </div>
        </>
    );
}

function QuickStarts({
    box,
    can,
}: {
    box: Props['box']['code'];
    can: Props['can'];
}) {
    const actions: Array<{
        title: string;
        description: string;
        href: string;
        icon: LucideIcon;
    }> = [];

    if (can.crearExpediente) {
        actions.push({
            title: box === 'haberes' ? 'Nuevo expediente' : 'Nueva multa',
            description:
                box === 'haberes'
                    ? 'Registrar el expediente que acaba de entrar'
                    : 'Registrar un expediente y su primera multa',
            href: box === 'haberes' ? nuevoExpediente().url : nuevaMulta().url,
            icon: FilePlus2,
        });
    }

    if (can.importarExtracto) {
        actions.push({
            title: 'Importar extracto',
            description: 'Cargar movimientos del banco para conciliarlos',
            href: importarExtracto().url,
            icon: FileSpreadsheet,
        });
    }

    if (actions.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="empezar" className="grid gap-4">
            <h2 id="empezar" className="text-lg font-semibold">
                Empezar algo
            </h2>
            <div className="grid gap-3 sm:grid-cols-2">
                {actions.map((action) => (
                    <Link
                        key={action.href}
                        href={action.href}
                        className="group flex items-center gap-4 rounded-lg border bg-card px-5 py-4 transition-colors hover:border-primary/40 hover:bg-accent/30 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <action.icon
                            className="size-5 text-primary"
                            aria-hidden="true"
                        />
                        <span className="min-w-0">
                            <span className="block text-sm font-semibold">
                                {action.title}
                            </span>
                            <span className="mt-0.5 block text-xs text-muted-foreground">
                                {action.description}
                            </span>
                        </span>
                        <ArrowRight
                            className="ml-auto size-4 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                            aria-hidden="true"
                        />
                    </Link>
                ))}
            </div>
        </section>
    );
}
