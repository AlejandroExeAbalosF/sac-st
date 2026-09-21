import { Head, Link, router } from '@inertiajs/react';
import { Ban, ChevronLeft, ChevronRight, Plus, Search } from 'lucide-react';
import { useState } from 'react';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import ExpedienteRow, {
    ExpedienteCard,
} from '@/features/haberes/components/expediente-row';
import HaberTable, {
    HaberCardList,
} from '@/features/haberes/components/haber-table';
import { cn } from '@/lib/utils';
import { create, index } from '@/routes/expedientes';

type Vista = 'expedientes' | 'haberes';

type Props = {
    vista: Vista;
    /** Solo en la vista de expedientes. */
    expedientes?: App.Modules.Haberes.Data.ExpedienteListItemData[];
    /** Solo en la vista de haberes. */
    haberes?: App.Modules.Haberes.Data.HaberRowData[];
    filters: { q: string; anulados: boolean };
    /** Cuántos hay anulados en total, se muestren o no. */
    anulados: number;
    pagination: {
        currentPage: number;
        lastPage: number;
        perPage: number;
        total: number;
        from: number | null;
        to: number | null;
        previous: string | null;
        next: string | null;
    };
};

export default function HaberesIndex({
    vista,
    expedientes = [],
    haberes = [],
    filters,
    pagination,
    anulados,
}: Props) {
    const [termino, setTermino] = useState(filters.q ?? '');
    const porHaber = vista === 'haberes';

    const consultar = (cambios: {
        q?: string;
        anulados?: boolean;
        vista?: Vista;
    }) =>
        router.get(
            index().url,
            {
                q: cambios.q ?? termino,
                // Solo viajan cuando están activos: así la dirección de la
                // pantalla normal queda limpia.
                ...((cambios.anulados ?? filters.anulados)
                    ? { anulados: 1 }
                    : {}),
                ...((cambios.vista ?? vista) === 'haberes'
                    ? { vista: 'haberes' }
                    : {}),
            },
            { preserveState: true, replace: true },
        );

    const buscar = (e: React.FormEvent) => {
        e.preventDefault();
        consultar({ q: termino });
    };

    return (
        <>
            <Head title="Haberes" />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Haberes en consignación"
                    eyebrow="Operación · Caja Haberes"
                    description="Expedientes y los haberes que cada uno reconoce."
                    actions={
                        <Button asChild>
                            <Link href={create()}>
                                <Plus className="size-4" aria-hidden="true" />
                                Nuevo expediente
                            </Link>
                        </Button>
                    }
                />

                {/*
                 * Dos lecturas del mismo material, no dos pantallas: se
                 * comparte el buscador, el interruptor de anulados y la
                 * paginación, y lo único que cambia es por qué fila se
                 * pregunta. La vista viaja en la dirección, así que el
                 * refresco y el enlace compartido caen donde corresponde.
                 */}
                <nav
                    aria-label="Vista del listado"
                    className="-mt-2 flex gap-1 border-b"
                >
                    <Pestana
                        activa={!porHaber}
                        onClick={() => consultar({ vista: 'expedientes' })}
                    >
                        Expedientes
                    </Pestana>
                    <Pestana
                        activa={porHaber}
                        onClick={() => consultar({ vista: 'haberes' })}
                    >
                        Haberes
                    </Pestana>
                </nav>

                <form onSubmit={buscar} className="flex gap-2">
                    <div className="relative flex-1 sm:max-w-md">
                        <Search
                            className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <input
                            type="search"
                            value={termino}
                            onChange={(e) => setTermino(e.target.value)}
                            aria-label={
                                porHaber
                                    ? 'Buscar beneficiario, DNI, expediente o empleador'
                                    : 'Buscar expediente, carátula, empleador, beneficiario o DNI'
                            }
                            placeholder={
                                porHaber
                                    ? 'Beneficiario, DNI, expediente o empleador…'
                                    : 'Expediente, carátula, empleador, beneficiario o DNI…'
                            }
                            className="h-9 w-full rounded-lg border bg-card pr-3 pl-9 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring"
                        />
                    </div>
                    <Button type="submit" variant="secondary">
                        Buscar
                    </Button>

                    {/*
                     * Los anulados no se borran: dejan registro de que
                     * estuvieron cargados. Por eso no se ven salvo que se
                     * los pida, y por eso el interruptor solo aparece
                     * cuando hay alguno.
                     */}
                    {(anulados > 0 || filters.anulados) && (
                        <Button
                            type="button"
                            variant={filters.anulados ? 'secondary' : 'ghost'}
                            className="ml-auto"
                            aria-pressed={filters.anulados}
                            onClick={() =>
                                consultar({ anulados: !filters.anulados })
                            }
                        >
                            <Ban className="size-4" aria-hidden="true" />
                            {filters.anulados
                                ? 'Ocultar anulados'
                                : `Ver anulados (${anulados})`}
                            <span className="sr-only">
                                {porHaber ? ' haberes' : ' expedientes'}
                            </span>
                        </Button>
                    )}
                </form>

                {/*
                 * El contenedor limita su alto y desplaza por dentro para
                 * que el encabezado pueda quedar fijo. Con pocas filas no
                 * se nota —no llega a desplazarse—; con cincuenta, es lo
                 * que evita tener que subir para recordar si esa columna
                 * era «Reconocido» o «Financiado».
                 */}
                {pagination.total === 0 ? (
                    <p className="rounded-lg border border-dashed px-5 py-12 text-center text-sm text-muted-foreground">
                        {filters.q === ''
                            ? porHaber
                                ? 'Todavía no hay haberes cargados.'
                                : 'Todavía no hay expedientes cargados.'
                            : porHaber
                              ? `Ningún haber coincide con «${filters.q}».`
                              : `Ningún expediente coincide con «${filters.q}».`}
                    </p>
                ) : (
                    <>
                        <div className="md:hidden">
                            {porHaber ? (
                                <HaberCardList haberes={haberes} />
                            ) : (
                                <div className="grid gap-3">
                                    {expedientes.map((expediente) => (
                                        <ExpedienteCard
                                            key={expediente.id}
                                            expediente={expediente}
                                        />
                                    ))}
                                </div>
                            )}
                        </div>

                        <div className="hidden max-h-[calc(100svh-20rem)] overflow-auto rounded-lg border bg-card shadow-raised md:block">
                            {porHaber ? (
                                <HaberTable haberes={haberes} />
                            ) : (
                                <table className="w-full border-collapse text-left">
                                    <caption className="sr-only">
                                        Expedientes; cada fila se despliega para
                                        ver sus haberes
                                    </caption>
                                    <thead className="sticky top-0 z-10">
                                        <tr className="border-b bg-muted text-xs tracking-wide text-field-label uppercase shadow-[0_1px_0_0_var(--border)]">
                                            <th
                                                scope="col"
                                                className="px-3 py-2.5 font-medium"
                                            >
                                                Expediente
                                            </th>
                                            <th
                                                scope="col"
                                                className="py-2.5 pr-5 font-medium"
                                            >
                                                Beneficiario
                                            </th>
                                            <th
                                                scope="col"
                                                className="py-2.5 pr-5 font-medium"
                                            >
                                                Empleador
                                            </th>
                                            <th
                                                scope="col"
                                                className="py-2.5 pr-5 text-right font-medium"
                                            >
                                                Reconocido
                                            </th>
                                            <th
                                                scope="col"
                                                className="py-2.5 pr-5 text-right font-medium"
                                            >
                                                Financiado
                                            </th>
                                            <th
                                                scope="col"
                                                className="py-2.5 pr-5 font-medium"
                                            >
                                                Estado
                                            </th>
                                            {/*
                                             * «Cargado» y no «Fecha»: la
                                             * fila ya trae la del papel
                                             * —«recibido»— y son dos cosas
                                             * distintas.
                                             */}
                                            <th
                                                scope="col"
                                                className="py-2.5 pr-5 font-medium"
                                            >
                                                Cargado
                                            </th>
                                            <th
                                                scope="col"
                                                className="py-2.5 pr-4 text-right font-medium"
                                            >
                                                Acciones
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {expedientes.map((expediente) => (
                                            <ExpedienteRow
                                                key={expediente.id}
                                                expediente={expediente}
                                            />
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </div>
                    </>
                )}

                <div className="flex flex-col gap-3 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
                    <p aria-live="polite">
                        {pagination.total === 0
                            ? porHaber
                                ? 'Sin haberes'
                                : 'Sin expedientes'
                            : pagination.total === 1
                              ? porHaber
                                  ? '1 haber'
                                  : '1 expediente'
                              : `${pagination.from}–${pagination.to} de ${pagination.total} ${porHaber ? 'haberes' : 'expedientes'}`}
                    </p>

                    {pagination.lastPage > 1 && (
                        <nav
                            className="flex items-center gap-2"
                            aria-label={
                                porHaber
                                    ? 'Paginación de haberes'
                                    : 'Paginación de expedientes'
                            }
                        >
                            {pagination.previous ? (
                                <Button asChild variant="outline" size="sm">
                                    <Link
                                        href={pagination.previous}
                                        preserveScroll
                                    >
                                        <ChevronLeft aria-hidden="true" />
                                        Anterior
                                    </Link>
                                </Button>
                            ) : (
                                <Button variant="outline" size="sm" disabled>
                                    <ChevronLeft aria-hidden="true" />
                                    Anterior
                                </Button>
                            )}

                            <span className="min-w-20 text-center font-mono tabular-nums">
                                Página {pagination.currentPage} de{' '}
                                {pagination.lastPage}
                            </span>

                            {pagination.next ? (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={pagination.next} preserveScroll>
                                        Siguiente
                                        <ChevronRight aria-hidden="true" />
                                    </Link>
                                </Button>
                            ) : (
                                <Button variant="outline" size="sm" disabled>
                                    Siguiente
                                    <ChevronRight aria-hidden="true" />
                                </Button>
                            )}
                        </nav>
                    )}
                </div>
            </div>
        </>
    );
}

/**
 * Una pestaña del listado.
 *
 * Es un botón y no un enlace porque cambiar de vista conserva lo que ya
 * está escrito en el buscador —la consulta viaja con `preserveState`—,
 * y `aria-current` es lo que le dice al lector de pantalla cuál de las
 * dos se está viendo.
 */
function Pestana({
    activa,
    onClick,
    children,
}: {
    activa: boolean;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-current={activa ? 'page' : undefined}
            className={cn(
                '-mb-px border-b-2 px-3 py-2 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                activa
                    ? 'border-primary text-primary'
                    : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground',
            )}
        >
            {children}
        </button>
    );
}
