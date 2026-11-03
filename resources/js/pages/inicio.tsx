import { Deferred, Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    FileSpreadsheet,
    FileText,
    Gavel,
    Plus,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import HomeSearch from '@/features/inicio/components/home-search';
import SetupChecklist from '@/features/inicio/components/setup-checklist';
import { BlockUnavailable } from '@/features/inicio/components/skeletons';
import { date } from '@/lib/format';
import { create as importarExtracto } from '@/routes/banco/extractos';
import { create as nuevoExpediente } from '@/routes/expedientes';
import { create as nuevaMulta } from '@/routes/multas';

type WorkQueue = App.Modules.Shared.Data.WorkQueueData;

type Props = {
    operationalDate: string;
    can: {
        verHaberes: boolean;
        verMultas: boolean;
        crearExpediente: boolean;
        crearMulta: boolean;
        importarExtracto: boolean;
    };
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
                                    createHref={
                                        can.crearExpediente
                                            ? nuevoExpediente().url
                                            : undefined
                                    }
                                    createLabel="Registrar haberes"
                                    createHint="Creá el expediente y agregá sus haberes."
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
                                    createHref={
                                        can.crearMulta
                                            ? nuevaMulta().url
                                            : undefined
                                    }
                                    createLabel="Nueva multa"
                                    createHint="Registrá una multa para iniciar su cobro."
                                    queues={(queues ?? []).filter((queue) =>
                                        queue.key.startsWith('multas_'),
                                    )}
                                />
                            )}
                        </div>
                    </Deferred>
                    {can.importarExtracto && (
                        <section className="mt-5 flex flex-col gap-4 rounded-lg border bg-muted/30 p-5 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex items-start gap-3">
                                <FileSpreadsheet
                                    className="mt-0.5 size-5 shrink-0 text-primary"
                                    aria-hidden="true"
                                />
                                <div>
                                    <h2 className="font-semibold">
                                        Extractos bancarios
                                    </h2>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Cargá el resumen del banco para
                                        identificar fondos y conciliar
                                        movimientos.
                                    </p>
                                </div>
                            </div>
                            <Button
                                asChild
                                variant="outline"
                                className="shrink-0"
                            >
                                <Link href={importarExtracto().url}>
                                    Cargar extracto
                                    <ArrowRight aria-hidden="true" />
                                </Link>
                            </Button>
                        </section>
                    )}
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
    createHref,
    createLabel,
    createHint,
}: {
    name: string;
    description: string;
    href: string;
    icon: LucideIcon;
    queues: WorkQueue[];
    createHref?: string;
    createLabel: string;
    createHint: string;
}) {
    const pending = queues.filter(
        (queue) => queue.count !== null && queue.count > 0,
    );

    return (
        <section className="flex min-h-64 flex-col rounded-lg border bg-card p-6 shadow-raised">
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

            <div className="mt-6 border-t pt-5">
                <div className="flex flex-wrap items-center gap-3">
                    {createHref && (
                        <Button asChild>
                            <Link href={createHref}>
                                <Plus aria-hidden="true" />
                                {createLabel}
                            </Link>
                        </Button>
                    )}
                    <Button asChild variant="ghost">
                        <Link href={href} prefetch>
                            Ver inicio de {name}
                            <ArrowRight aria-hidden="true" />
                        </Link>
                    </Button>
                </div>
                {createHref && (
                    <p className="mt-3 text-xs text-muted-foreground">
                        {createHint}
                    </p>
                )}
            </div>
        </section>
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
