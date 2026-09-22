import { Head, router, usePage } from '@inertiajs/react';
import { FileSpreadsheet, ScrollText } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import PageHeader from '@/components/page-header';
import type { PaginationData } from '@/components/pagination-footer';
import PaginationFooter from '@/components/pagination-footer';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import OperationTable from '@/features/auditoria/components/operation-table';
import { exportar, index } from '@/routes/configuracion/auditoria';

type Evento = App.Modules.Shared.Data.OperationAuditEventData;

type Filtros = {
    desde: string | null;
    hasta: string | null;
    usuario: number | 'sistema' | null;
    accion: string | null;
    entidad: string | null;
    criticas: boolean;
};

type Props = {
    events: { data: Evento[] } & PaginationData;
    users: { id: number; name: string }[];
    actionGroups: {
        category: string;
        actions: { value: string; label: string; critical: boolean }[];
    }[];
    subjects: { value: string; label: string }[];
    filters: Filtros;
    can: { export: boolean };
};

/** El valor que usa el `Select` para «sin filtro»: no admite cadena vacía. */
const TODOS = 'todos';

const SIN_FILTROS: Filtros = {
    desde: null,
    hasta: null,
    usuario: null,
    accion: null,
    entidad: null,
    criticas: false,
};

/** Los filtros como van en la dirección: lo vacío no viaja. */
function consulta(filtros: Filtros) {
    return {
        desde: filtros.desde ?? undefined,
        hasta: filtros.hasta ?? undefined,
        usuario: filtros.usuario ?? undefined,
        accion: filtros.accion ?? undefined,
        entidad: filtros.entidad ?? undefined,
        criticas: filtros.criticas ? 1 : undefined,
    };
}

/**
 * Qué se hizo en el sistema, quién y cuándo, de punta a punta.
 *
 * Los historiales de cada expediente responden por una cosa. Esta pantalla
 * responde las preguntas que no empiezan por un expediente: quién anuló
 * cobros este mes, quién reabrió un período, quién forzó un CBU. «Solo
 * críticas» es el atajo a eso, que es lo primero que un control mira.
 *
 * El Excel baja lo que se está mirando —los filtros aplicados, no los que
 * están a medio escribir en el formulario—.
 */
export default function Auditoria({
    events,
    users,
    actionGroups,
    subjects,
    filters,
    can,
}: Props) {
    const [borrador, setBorrador] = useState<Filtros>(filters);
    const { errors } = usePage<{
        errors: Partial<Record<keyof Filtros, string>>;
    }>().props;

    const aplicar = (e: React.FormEvent) => {
        e.preventDefault();

        router.get(index().url, consulta(borrador), {
            preserveState: true,
            replace: true,
        });
    };

    const limpiar = () => {
        setBorrador(SIN_FILTROS);
        router.get(index().url, {}, { replace: true });
    };

    const alternarCriticas = (criticas: boolean) => {
        const siguiente = { ...borrador, criticas };

        setBorrador(siguiente);
        router.get(index().url, consulta(siguiente), {
            preserveState: true,
            replace: true,
        });
    };

    const hayFiltros =
        filters.criticas ||
        Object.entries(filters).some(
            ([clave, valor]) => clave !== 'criticas' && valor !== null,
        );

    return (
        <>
            <Head title="Auditoría" />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Configuración"
                    title="Auditoría"
                    description="Qué se hizo en el sistema, sobre qué, quién y cuándo."
                    actions={
                        can.export && (
                            <Button variant="outline" size="sm" asChild>
                                <a
                                    href={
                                        exportar({ query: consulta(filters) })
                                            .url
                                    }
                                >
                                    <FileSpreadsheet className="size-4" />
                                    Exportar a Excel
                                </a>
                            </Button>
                        )
                    }
                />

                <section className="rounded-lg border bg-card shadow-raised">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
                        <h2 className="flex items-center gap-2 text-sm font-semibold">
                            <ScrollText
                                className="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            Operaciones registradas
                        </h2>
                        <label className="flex items-center gap-2 text-sm">
                            <Switch
                                checked={borrador.criticas}
                                onCheckedChange={alternarCriticas}
                            />
                            Solo críticas
                        </label>
                    </header>

                    <form
                        onSubmit={aplicar}
                        className="grid gap-3 border-b bg-muted/30 px-5 py-4 sm:grid-cols-2 lg:grid-cols-6"
                    >
                        <div className="grid content-start gap-1.5 lg:col-span-2">
                            <Label htmlFor="accion" className="text-xs">
                                Acción
                            </Label>
                            <Select
                                value={borrador.accion ?? TODOS}
                                onValueChange={(valor) =>
                                    setBorrador((previo) => ({
                                        ...previo,
                                        accion: valor === TODOS ? null : valor,
                                    }))
                                }
                            >
                                <SelectTrigger id="accion" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={TODOS}>
                                        Todas las acciones
                                    </SelectItem>
                                    {actionGroups.map((grupo) => (
                                        <SelectGroup key={grupo.category}>
                                            <SelectLabel>
                                                {grupo.category}
                                            </SelectLabel>
                                            {grupo.actions.map((accion) => (
                                                <SelectItem
                                                    key={accion.value}
                                                    value={accion.value}
                                                >
                                                    {accion.label}
                                                    {accion.critical && (
                                                        <span className="text-warning-strong">
                                                            {' '}
                                                            · crítica
                                                        </span>
                                                    )}
                                                </SelectItem>
                                            ))}
                                        </SelectGroup>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.accion} />
                        </div>

                        <div className="grid content-start gap-1.5">
                            <Label htmlFor="entidad" className="text-xs">
                                Sobre
                            </Label>
                            <Select
                                value={borrador.entidad ?? TODOS}
                                onValueChange={(valor) =>
                                    setBorrador((previo) => ({
                                        ...previo,
                                        entidad: valor === TODOS ? null : valor,
                                    }))
                                }
                            >
                                <SelectTrigger id="entidad" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={TODOS}>
                                        Cualquier entidad
                                    </SelectItem>
                                    {subjects.map((sujeto) => (
                                        <SelectItem
                                            key={sujeto.value}
                                            value={sujeto.value}
                                        >
                                            {sujeto.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.entidad} />
                        </div>

                        <div className="grid content-start gap-1.5">
                            <Label htmlFor="usuario" className="text-xs">
                                Usuario
                            </Label>
                            <Select
                                value={
                                    borrador.usuario === null
                                        ? TODOS
                                        : String(borrador.usuario)
                                }
                                onValueChange={(valor) =>
                                    setBorrador((previo) => ({
                                        ...previo,
                                        usuario:
                                            valor === TODOS
                                                ? null
                                                : valor === 'sistema'
                                                  ? 'sistema'
                                                  : Number(valor),
                                    }))
                                }
                            >
                                <SelectTrigger id="usuario" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={TODOS}>
                                        Todos los usuarios
                                    </SelectItem>
                                    {/*
                                     * Lo que no provocó una persona: una
                                     * importación, un comando programado.
                                     */}
                                    <SelectItem value="sistema">
                                        El sistema
                                    </SelectItem>
                                    {users.map((usuario) => (
                                        <SelectItem
                                            key={usuario.id}
                                            value={String(usuario.id)}
                                        >
                                            {usuario.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.usuario} />
                        </div>

                        <div className="grid content-start gap-1.5">
                            <Label htmlFor="desde" className="text-xs">
                                Desde
                            </Label>
                            <Input
                                id="desde"
                                type="date"
                                value={borrador.desde ?? ''}
                                onChange={(e) =>
                                    setBorrador((previo) => ({
                                        ...previo,
                                        desde:
                                            e.target.value === ''
                                                ? null
                                                : e.target.value,
                                    }))
                                }
                            />
                            <InputError message={errors.desde} />
                        </div>

                        <div className="grid content-start gap-1.5">
                            <Label htmlFor="hasta" className="text-xs">
                                Hasta
                            </Label>
                            <Input
                                id="hasta"
                                type="date"
                                value={borrador.hasta ?? ''}
                                onChange={(e) =>
                                    setBorrador((previo) => ({
                                        ...previo,
                                        hasta:
                                            e.target.value === ''
                                                ? null
                                                : e.target.value,
                                    }))
                                }
                            />
                            <InputError message={errors.hasta} />
                        </div>

                        <div className="flex items-end gap-2 sm:col-span-2 lg:col-span-6">
                            <Button type="submit" variant="secondary">
                                Filtrar
                            </Button>
                            {hayFiltros && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={limpiar}
                                >
                                    Limpiar
                                </Button>
                            )}
                        </div>
                    </form>

                    <OperationTable
                        events={events.data}
                        emptyLabel={
                            hayFiltros
                                ? 'Ninguna operación coincide con el filtro.'
                                : 'Todavía no hay operaciones registradas.'
                        }
                    />
                </section>

                <PaginationFooter
                    pagination={events}
                    singular="operación"
                    plural="operaciones"
                />
            </div>
        </>
    );
}
