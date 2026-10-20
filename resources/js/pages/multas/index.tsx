import { Head, Link, router } from '@inertiajs/react';
import { FileSpreadsheet, Plus, Search } from 'lucide-react';
import { useState } from 'react';
import Money from '@/components/money';
import PageHeader from '@/components/page-header';
import PaginationFooter from '@/components/pagination-footer';
import type { PaginationData } from '@/components/pagination-footer';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { DEBT_STATUS, partialDate } from '@/features/multas/labels';
import { isNonZero } from '@/lib/format';
import { create, exportar, index, show } from '@/routes/multas';
import { show as showCase } from '@/routes/multas/expedientes';

type Multa = App.Modules.Multas.Data.FineListItemData;

type Filtros = { q: string; estado: string; anio: number | null };

/** Solo viaja lo que filtra: la dirección queda limpia. */
function consulta(filtros: Filtros): Record<string, string | number> {
    return {
        ...(filtros.q !== '' ? { q: filtros.q } : {}),
        ...(filtros.estado !== '' ? { estado: filtros.estado } : {}),
        ...(filtros.anio !== null ? { anio: filtros.anio } : {}),
    };
}

type Props = {
    multas: Multa[];
    filters: Filtros;
    canRegister: boolean;
    pagination: PaginationData;
};

/**
 * La cuenta corriente de multas.
 *
 * Es la pantalla que reemplaza buscar en el Excel (RF-01): quien viene a
 * pagar trae un número de expediente, de resolución o de acta, y con
 * cualquiera de los tres se llega a la multa.
 */
export default function MultasIndex({
    multas,
    filters,
    canRegister,
    pagination,
}: Props) {
    const [termino, setTermino] = useState(filters.q);

    const consultar = (cambios: Partial<Filtros>) => {
        const siguiente = { ...filters, q: termino, ...cambios };

        router.get(index().url, consulta(siguiente), {
            preserveState: true,
            replace: true,
        });
    };

    const buscar = (e: React.FormEvent) => {
        e.preventDefault();
        consultar({ q: termino });
    };

    return (
        <>
            <Head title="Multas" />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Cuenta corriente de multas"
                    eyebrow="Operación · Caja Multas"
                    description="Las multas laborales y de higiene y seguridad, con lo que se debe de cada una."
                    actions={
                        <>
                            {/* Lo que se ve filtrado, entero y sin paginar. */}
                            <Button variant="outline" asChild>
                                <a
                                    href={
                                        exportar({ query: consulta(filters) })
                                            .url
                                    }
                                >
                                    <FileSpreadsheet
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Exportar a Excel
                                </a>
                            </Button>
                            {canRegister && (
                                <Button asChild>
                                    <Link href={create()}>
                                        <Plus
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        Nueva multa
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                <form
                    onSubmit={buscar}
                    className="flex flex-wrap items-end gap-3"
                >
                    <div className="relative flex-1 sm:max-w-md">
                        <Search
                            className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <input
                            type="search"
                            value={termino}
                            onChange={(e) => setTermino(e.target.value)}
                            aria-label="Buscar por expediente, resolución, acta o carátula"
                            placeholder="Expediente, resolución, acta o carátula…"
                            className="h-9 w-full rounded-lg border bg-card pr-3 pl-9 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring"
                        />
                    </div>
                    <Button type="submit" variant="secondary">
                        Buscar
                    </Button>

                    <div className="grid gap-1.5">
                        <Label htmlFor="estado" className="text-xs">
                            Deuda
                        </Label>
                        <Select
                            value={
                                filters.estado === '' ? 'all' : filters.estado
                            }
                            onValueChange={(valor) =>
                                consultar({
                                    estado: valor === 'all' ? '' : valor,
                                })
                            }
                        >
                            <SelectTrigger id="estado" className="min-w-40">
                                <SelectValue placeholder="Todas" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Todas</SelectItem>
                                <SelectItem value="con-deuda">
                                    Con deuda
                                </SelectItem>
                                <SelectItem value="sin-deuda">
                                    Sin deuda
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="anio" className="text-xs">
                            Año de registro
                        </Label>
                        <input
                            id="anio"
                            type="number"
                            inputMode="numeric"
                            min={1990}
                            max={2100}
                            defaultValue={filters.anio ?? ''}
                            onBlur={(e) =>
                                consultar({
                                    anio:
                                        e.target.value === ''
                                            ? null
                                            : Number(e.target.value),
                                })
                            }
                            placeholder="Todos"
                            className="h-9 w-28 rounded-lg border bg-card px-3 text-sm shadow-xs outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        />
                    </div>
                </form>

                {pagination.total === 0 ? (
                    <p className="rounded-lg border border-dashed px-5 py-12 text-center text-sm text-muted-foreground">
                        {filters.q === '' &&
                        filters.estado === '' &&
                        filters.anio === null
                            ? 'Todavía no hay multas cargadas.'
                            : 'Ninguna multa coincide con la búsqueda.'}
                    </p>
                ) : (
                    <div className="max-h-[calc(100svh-20rem)] overflow-auto rounded-lg border bg-card shadow-raised [contain:inline-size]">
                        {/*
                         * `contain: inline-size` para que el ancho de la
                         * tabla no empuje el de la pantalla: se desplaza por
                         * dentro.
                         */}
                        <table className="w-full border-collapse text-left">
                            <caption className="sr-only">
                                Multas, de la última registrada a la primera
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
                                        Carátula
                                    </th>
                                    <th
                                        scope="col"
                                        className="py-2.5 pr-5 font-medium"
                                    >
                                        Resolución y actas
                                    </th>
                                    <th
                                        scope="col"
                                        className="py-2.5 pr-5 font-medium"
                                    >
                                        Registro
                                    </th>
                                    <th
                                        scope="col"
                                        className="py-2.5 pr-5 text-right font-medium"
                                    >
                                        Importe
                                    </th>
                                    <th
                                        scope="col"
                                        className="py-2.5 pr-5 text-right font-medium"
                                    >
                                        Saldo
                                    </th>
                                    <th
                                        scope="col"
                                        className="py-2.5 pr-4 font-medium"
                                    >
                                        Estado
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {multas.map((multa) => (
                                    <tr
                                        key={multa.id}
                                        className="border-b align-top last:border-b-0 hover:bg-muted/40"
                                    >
                                        <td className="px-3 py-2.5 font-mono text-sm whitespace-nowrap">
                                            <Link
                                                href={showCase(multa.caseId)}
                                                className="hover:underline"
                                            >
                                                {multa.caseLabel}
                                            </Link>
                                        </td>
                                        <td className="py-2.5 pr-5 text-sm">
                                            <Link
                                                href={show(multa.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {multa.caption}
                                            </Link>
                                            {multa.personName && (
                                                <p className="text-xs text-muted-foreground">
                                                    {multa.personName}
                                                </p>
                                            )}
                                        </td>
                                        <td className="py-2.5 pr-5 text-xs text-muted-foreground">
                                            {multa.originDocuments.join(' · ')}
                                        </td>
                                        <td className="py-2.5 pr-5 text-sm whitespace-nowrap">
                                            {partialDate(
                                                multa.registeredOn,
                                                multa.registeredPrecision,
                                            )}
                                        </td>
                                        <td className="py-2.5 pr-5 text-right text-sm">
                                            <Money
                                                value={multa.balance.obligation}
                                            />
                                        </td>
                                        <td className="py-2.5 pr-5 text-right text-sm">
                                            <Money
                                                value={multa.balance.debt}
                                                dimWhenZero
                                            />
                                        </td>
                                        <td className="py-2.5 pr-4">
                                            <div className="flex flex-wrap gap-1">
                                                <StatusBadge
                                                    label={
                                                        DEBT_STATUS[
                                                            multa.balance.status
                                                        ].label
                                                    }
                                                    tone={
                                                        DEBT_STATUS[
                                                            multa.balance.status
                                                        ].tone
                                                    }
                                                />
                                                {isNonZero(
                                                    multa.balance.excess,
                                                ) && (
                                                    <StatusBadge
                                                        label="Con excedente"
                                                        tone="action"
                                                    />
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <PaginationFooter
                    pagination={pagination}
                    singular="multa"
                    plural="multas"
                />
            </div>
        </>
    );
}
