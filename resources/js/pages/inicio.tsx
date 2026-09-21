import { Deferred, Head } from '@inertiajs/react';
import GlobalSearch from '@/components/global-search';
import PageHeader from '@/components/page-header';
import CashBoxSummary from '@/features/inicio/components/cash-box-summary';
import CashDayCard from '@/features/inicio/components/cash-day-card';
import QueueCard from '@/features/inicio/components/queue-card';
import QuickActions from '@/features/inicio/components/quick-actions';
import RecentAccessList from '@/features/inicio/components/recent-access';
import RecentMovements from '@/features/inicio/components/recent-movements';
import {
    BlockUnavailable,
    CashBoxSummarySkeleton,
    CashDaySkeleton,
    ListSkeleton,
    QueueGridSkeleton,
} from '@/features/inicio/components/skeletons';
import { date } from '@/lib/format';

type Props = {
    operationalDate: string;
    can: {
        verCaja: boolean;
        verMovimientos: boolean;
        verVolumen: boolean;
        verColas: boolean;
        crearExpediente: boolean;
        importarExtracto: boolean;
        verExpedientes: boolean;
    };
    recentAccess: App.Modules.Shared.Data.RecentAccessData[];

    /*
     * Diferidas. Llegan `undefined` en la primera respuesta y por eso son
     * opcionales: el tipo dice la verdad sobre lo que hay en pantalla
     * mientras el esqueleto está puesto, y `<Deferred>` se encarga de que
     * el bloque no se renderice hasta que el dato exista.
     */
    queues?: App.Modules.Shared.Data.WorkQueueData[];
    cashDay?: App.Modules.Ledger.Data.CashDayStatusData | null;
    cashBoxes?: App.Modules.Shared.Data.CashBoxSummaryData[];
    movements?: App.Modules.Ledger.Data.MovementRowData[];
};

/**
 * El tablero de inicio.
 *
 * El orden de la pantalla es el de las preguntas de la mañana: dónde
 * busco, cuánto hay en la caja, qué me está esperando, qué arranco, qué
 * pasó. Lo que es contexto de jefatura —el volumen por caja— va al pie:
 * responde una pregunta legítima que las colas no responden, pero no es
 * lo que hace falta para trabajar.
 */
export default function Inicio({
    operationalDate,
    can,
    recentAccess,
    queues,
    cashDay,
    cashBoxes,
    movements,
}: Props) {
    return (
        <>
            <Head title="Inicio" />

            <div className="flex flex-col gap-8 p-4 sm:p-6">
                <PageHeader
                    title="Panel administrativo"
                    eyebrow={`Fecha operativa · ${date(operationalDate)} · Caja Haberes`}
                    description="Lo que espera que alguien haga algo, en el orden del circuito."
                />

                <GlobalSearch disabled={!can.verExpedientes} />

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

                {can.verColas && (
                    <section aria-labelledby="colas">
                        <div className="mb-4 max-w-2xl">
                            <h2
                                id="colas"
                                className="text-xs font-semibold tracking-wide text-primary uppercase"
                            >
                                Trabajo del circuito
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Primero los bloqueos; después, lo que se puede
                                resolver y lo que sigue su curso.
                            </p>
                        </div>
                        <Deferred
                            data="queues"
                            fallback={<QueueGridSkeleton />}
                            rescue={
                                <BlockUnavailable what="las colas de trabajo" />
                            }
                        >
                            <QueueGroups queues={queues ?? []} />
                        </Deferred>
                    </section>
                )}

                <section aria-labelledby="acciones">
                    <h2
                        id="acciones"
                        className="mb-2 text-xs font-semibold tracking-wide text-primary uppercase"
                    >
                        Empezar algo
                    </h2>
                    <QuickActions can={can} />
                </section>

                <div className="grid gap-6 lg:grid-cols-2">
                    {can.verMovimientos && (
                        <section
                            aria-labelledby="movimientos"
                            className="overflow-hidden rounded-lg border bg-card shadow-raised"
                        >
                            <div className="flex items-center gap-2 border-b px-5 py-4">
                                <h2
                                    id="movimientos"
                                    className="text-sm font-semibold"
                                >
                                    Movimientos registrados recientemente
                                </h2>
                                <span className="ml-auto hidden text-xs text-muted-foreground sm:inline">
                                    fecha de carga y fecha operativa
                                </span>
                            </div>
                            <Deferred
                                data="movements"
                                fallback={<ListSkeleton rows={5} />}
                                rescue={
                                    <BlockUnavailable what="los movimientos" />
                                }
                            >
                                <RecentMovements movements={movements ?? []} />
                            </Deferred>
                        </section>
                    )}

                    <section
                        aria-labelledby="accesos"
                        className="overflow-hidden rounded-lg border bg-card shadow-raised"
                    >
                        <div className="flex items-center gap-2 border-b px-5 py-4">
                            <h2 id="accesos" className="text-sm font-semibold">
                                Tu actividad reciente
                            </h2>
                            <span className="ml-auto hidden text-xs text-muted-foreground sm:inline">
                                últimos 5 accesos de tu cuenta
                            </span>
                        </div>
                        <RecentAccessList events={recentAccess} />
                    </section>
                </div>

                {can.verVolumen && (
                    <section aria-labelledby="cajas">
                        <h2
                            id="cajas"
                            className="mb-2 text-xs font-semibold tracking-wide text-primary uppercase"
                        >
                            Volumen por caja
                        </h2>
                        <Deferred
                            data="cashBoxes"
                            fallback={<CashBoxSummarySkeleton />}
                            rescue={
                                <BlockUnavailable what="el volumen por caja" />
                            }
                        >
                            <CashBoxSummary boxes={cashBoxes ?? []} />
                        </Deferred>
                    </section>
                )}
            </div>
        </>
    );
}

type WorkQueue = App.Modules.Shared.Data.WorkQueueData;

/**
 * Los grupos, y qué cola va en cada uno.
 *
 * Es una lista blanca: una cola que el servidor calcula y que no figura
 * acá **no se dibuja, y nada avisa**. Pasó con dos.
 * `test_toda_cola_del_tablero_tiene_donde_dibujarse` lo vigila.
 */
const GRUPOS_DE_COLAS = [
    {
        title: 'Bloqueos',
        description: 'Falta un dato o una condición para poder continuar.',
        keys: ['beneficiarios_sin_cbu'],
    },
    {
        title: 'Para resolver',
        description: 'Casos que ya admiten una acción del área.',
        keys: [
            'fondos_sin_identificar',
            'cuotas_sin_orden',
            'egresos_por_validar',
            'caja_sin_cerrar',
            'caja_mes_sin_cerrar',
        ],
    },
    {
        title: 'En seguimiento',
        description: 'Casos que avanzaron y esperan una respuesta externa.',
        /*
         * El comprobante espera al extracto, no a alguien del área: hasta
         * que el crédito no aparece no hay nada que cruzar. Por eso va acá
         * y no en «Para resolver», al revés que los fondos sin identificar,
         * que ya están en la cuenta esperando dueño.
         */
        keys: ['ordenes_en_saf', 'comprobantes_sin_cruzar'],
    },
] as const;

function QueueGroups({ queues }: { queues: WorkQueue[] }) {
    if (queues.length === 0) {
        return (
            <p className="rounded-lg border border-dashed bg-muted/40 px-5 py-8 text-center text-sm text-muted-foreground">
                No tenés colas de trabajo habilitadas para tus permisos.
            </p>
        );
    }

    return (
        <div className="space-y-6">
            {GRUPOS_DE_COLAS.map((group) => {
                const items = queues.filter((queue) =>
                    group.keys.some((key) => key === queue.key),
                );

                if (items.length === 0) {
                    return null;
                }

                return (
                    <section
                        key={group.title}
                        aria-labelledby={`grupo-${group.keys[0]}`}
                    >
                        <div className="mb-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <h3
                                id={`grupo-${group.keys[0]}`}
                                className="text-sm font-semibold"
                            >
                                {group.title}
                            </h3>
                            <p className="text-xs text-muted-foreground">
                                {group.description}
                            </p>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            {items.map((queue) => (
                                <QueueCard key={queue.key} queue={queue} />
                            ))}
                        </div>
                    </section>
                );
            })}
        </div>
    );
}
