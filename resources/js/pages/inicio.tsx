import { Deferred, Head, Link } from '@inertiajs/react';
import { ArrowRight, FileText, Gavel } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import PageHeader from '@/components/page-header';
import { Skeleton } from '@/components/ui/skeleton';
import HomeSearch from '@/features/inicio/components/home-search';
import SetupChecklist from '@/features/inicio/components/setup-checklist';
import { BlockUnavailable } from '@/features/inicio/components/skeletons';
import { date } from '@/lib/format';

type WorkQueue = App.Modules.Shared.Data.WorkQueueData;

type Props = {
    operationalDate: string;
    can: { verHaberes: boolean; verMultas: boolean };
    setup: App.Modules.Shared.Data.SetupStepData[] | null;
    queues?: WorkQueue[];
};

/** Portada breve que deriva al operador al circuito correcto. */
export default function Inicio({ operationalDate, can, setup, queues }: Props) {
    return (
        <>
            <Head title="Inicio" />
            <div className="flex w-full flex-col gap-8 p-4 sm:p-6">
                <PageHeader
                    title="Inicio"
                    eyebrow={`Fecha operativa · ${date(operationalDate)}`}
                    description="Elegí una caja para ver su estado y el trabajo que necesita atención."
                />

                {setup && <SetupChecklist steps={setup} />}

                <HomeSearch>
                    <Deferred
                        data="queues"
                        fallback={<CashBoxesSkeleton />}
                        rescue={
                            <BlockUnavailable what="el estado de las cajas" />
                        }
                    >
                        <div className="grid gap-5 lg:grid-cols-2">
                            {can.verHaberes && (
                                <CashBoxEntry
                                    name="Haberes"
                                    description="Expedientes, pagos y caja diaria"
                                    href="/haberes/inicio"
                                    icon={FileText}
                                    queues={(queues ?? []).filter(
                                        (queue) =>
                                            !queue.key.startsWith('multas_'),
                                    )}
                                />
                            )}
                            {can.verMultas && (
                                <CashBoxEntry
                                    name="Multas"
                                    description="Cobros, depósitos y caja diaria"
                                    href="/multas/inicio"
                                    icon={Gavel}
                                    queues={(queues ?? []).filter((queue) =>
                                        queue.key.startsWith('multas_'),
                                    )}
                                />
                            )}
                        </div>
                    </Deferred>
                </HomeSearch>
            </div>
        </>
    );
}

function CashBoxEntry({
    name,
    description,
    href,
    icon: Icon,
    queues,
}: {
    name: string;
    description: string;
    href: string;
    icon: LucideIcon;
    queues: WorkQueue[];
}) {
    const pending = queues.filter(
        (queue) => queue.count !== null && queue.count > 0,
    );

    return (
        <Link
            href={href}
            prefetch
            className="group flex min-h-64 flex-col rounded-lg border bg-card p-6 shadow-raised transition-[border-color,background-color,transform] duration-200 hover:-translate-y-0.5 hover:border-primary/40 hover:bg-accent/25 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-reduce:transform-none"
        >
            <div className="flex items-start gap-4">
                <span
                    className="flex size-10 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary"
                    aria-hidden="true"
                >
                    <Icon className="size-5" />
                </span>
                <div className="min-w-0">
                    <h2 className="text-xl font-semibold tracking-tight">
                        {name}
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {description}
                    </p>
                </div>
                <ArrowRight
                    className="ml-auto size-5 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-1"
                    aria-hidden="true"
                />
            </div>

            <div className="mt-7 flex flex-1 flex-col gap-3 border-t pt-5">
                <p className="text-sm font-medium">
                    {pending.length === 0
                        ? 'Sin tareas pendientes'
                        : pending.length === 1
                          ? '1 frente requiere atención'
                          : `${pending.length} frentes requieren atención`}
                </p>
                {pending.length > 0 && (
                    <ul className="flex flex-col gap-2">
                        {pending.slice(0, 3).map((queue) => (
                            <li
                                key={queue.key}
                                className="flex items-baseline gap-3 text-sm text-muted-foreground"
                            >
                                <span className="min-w-6 font-semibold text-foreground tabular-nums">
                                    {queue.count}
                                </span>
                                <span className="line-clamp-1">
                                    {queue.title}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <span className="mt-5 text-sm font-semibold text-primary">
                Ver inicio de {name}
            </span>
        </Link>
    );
}

function CashBoxesSkeleton() {
    return (
        <div
            className="grid gap-5 lg:grid-cols-2"
            role="status"
            aria-label="Cargando cajas"
        >
            {[0, 1].map((item) => (
                <div
                    key={item}
                    className="min-h-64 rounded-lg border bg-card p-6"
                >
                    <div className="flex gap-4">
                        <Skeleton className="size-10 rounded-md" />
                        <div className="flex-1">
                            <Skeleton className="h-6 w-28" />
                            <Skeleton className="mt-2 h-4 w-56" />
                        </div>
                    </div>
                    <div className="mt-7 border-t pt-5">
                        <Skeleton className="h-4 w-44" />
                        <Skeleton className="mt-4 h-4 w-64" />
                        <Skeleton className="mt-3 h-4 w-52" />
                    </div>
                </div>
            ))}
        </div>
    );
}
