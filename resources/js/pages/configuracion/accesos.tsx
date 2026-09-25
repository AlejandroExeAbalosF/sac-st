import { Head, router, usePage } from '@inertiajs/react';
import { History, MonitorSmartphone } from 'lucide-react';
import { useState } from 'react';
import FormError from '@/components/form-error';
import PageHeader from '@/components/page-header';
import type { PaginationData } from '@/components/pagination-footer';
import PaginationFooter from '@/components/pagination-footer';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AccessTable from '@/features/cuenta/components/access-table';
import SessionList from '@/features/cuenta/components/session-list';
import { index } from '@/routes/configuracion/accesos';
import { destroy as cerrarSesion } from '@/routes/configuracion/sesiones';

type Sesion = App.Modules.Shared.Data.ActiveSessionData;
type Evento = App.Modules.Shared.Data.AccessEventData;

type Filtros = {
    usuario: number | null;
    tipo: string | null;
    ip: string | null;
    desde: string | null;
    hasta: string | null;
};

type Props = {
    sessions: Sesion[];
    events: { data: Evento[] } & PaginationData;
    users: { id: number; name: string }[];
    eventTypes: { value: string; label: string }[];
    filters: Filtros;
    can: { seeSessions: boolean; revokeSessions: boolean };
};

/** El valor que usa el `Select` para «sin filtro»: no admite cadena vacía. */
const TODOS = 'todos';

/**
 * Accesos y sesiones de todo el sistema.
 *
 * Lo que hace útil a esta pantalla no son los ingresos correctos sino los
 * intentos fallidos, incluidos los que se hicieron contra usuarios que no
 * existen: esas filas no apuntan a nadie y, correlacionadas por IP, son la
 * única señal temprana de que alguien está probando nombres.
 */
export default function Accesos({
    sessions,
    events,
    users,
    eventTypes,
    filters,
    can,
}: Props) {
    const [borrador, setBorrador] = useState<Filtros>(filters);
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    const aplicar = (e: React.FormEvent) => {
        e.preventDefault();

        router.get(
            index().url,
            {
                usuario: borrador.usuario ?? undefined,
                tipo: borrador.tipo ?? undefined,
                ip: borrador.ip ?? undefined,
                desde: borrador.desde ?? undefined,
                hasta: borrador.hasta ?? undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const limpiar = () => {
        setBorrador({
            usuario: null,
            tipo: null,
            ip: null,
            desde: null,
            hasta: null,
        });
        router.get(index().url, {}, { replace: true });
    };

    const hayFiltros = Object.values(filters).some((valor) => valor !== null);

    return (
        <>
            <Head title="Accesos y sesiones" />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Configuración"
                    title="Accesos y sesiones"
                    description="Quién entró, desde dónde, y qué sesiones siguen abiertas."
                />

                {can.seeSessions && (
                    <section className="rounded-lg border bg-card shadow-raised">
                        <header className="border-b px-5 py-3.5">
                            <h2 className="flex items-center gap-2 text-sm font-semibold">
                                <MonitorSmartphone
                                    className="size-4 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                Sesiones abiertas
                            </h2>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Cerrar una sesión no desactiva al usuario:
                                vuelve a entrar con su contraseña.
                            </p>
                        </header>

                        {/*
                         * Los rechazos del cierre —la sesión propia, la de un
                         * super-admin— son de la acción, no de un campo.
                         */}
                        <div className="px-5 pt-3 empty:hidden">
                            <FormError message={errors.sessionId} />
                        </div>

                        <SessionList
                            sessions={sessions}
                            showUser
                            emptyLabel="No hay ninguna sesión abierta en el sistema."
                            onRevoke={
                                can.revokeSessions
                                    ? (session) =>
                                          router.delete(cerrarSesion().url, {
                                              data: { sessionId: session.id },
                                              preserveScroll: true,
                                          })
                                    : undefined
                            }
                        />
                    </section>
                )}

                <section className="rounded-lg border bg-card shadow-raised">
                    <header className="border-b px-5 py-3.5">
                        <h2 className="flex items-center gap-2 text-sm font-semibold">
                            <History
                                className="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            Historial de accesos
                        </h2>
                    </header>

                    <form
                        onSubmit={aplicar}
                        className="grid gap-3 border-b bg-muted/30 px-5 py-4 sm:grid-cols-2 lg:grid-cols-6"
                    >
                        <div className="grid gap-1.5 lg:col-span-2">
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
                                                : Number(valor),
                                    }))
                                }
                            >
                                <SelectTrigger id="usuario">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={TODOS}>
                                        Todos los usuarios
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
                        </div>

                        <div className="grid gap-1.5 lg:col-span-2">
                            <Label htmlFor="tipo" className="text-xs">
                                Tipo de evento
                            </Label>
                            <Select
                                value={borrador.tipo ?? TODOS}
                                onValueChange={(valor) =>
                                    setBorrador((previo) => ({
                                        ...previo,
                                        tipo: valor === TODOS ? null : valor,
                                    }))
                                }
                            >
                                <SelectTrigger id="tipo">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={TODOS}>
                                        Todos los eventos
                                    </SelectItem>
                                    {eventTypes.map((tipo) => (
                                        <SelectItem
                                            key={tipo.value}
                                            value={tipo.value}
                                        >
                                            {tipo.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="ip" className="text-xs">
                                IP
                            </Label>
                            <Input
                                id="ip"
                                value={borrador.ip ?? ''}
                                onChange={(e) =>
                                    setBorrador((previo) => ({
                                        ...previo,
                                        ip:
                                            e.target.value === ''
                                                ? null
                                                : e.target.value,
                                    }))
                                }
                                className="font-mono tabular-nums"
                                placeholder="10.0.0.1"
                            />
                        </div>

                        <div className="grid gap-1.5">
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
                        </div>

                        <div className="grid gap-1.5">
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

                    <AccessTable
                        events={events.data}
                        showUser
                        emptyLabel={
                            hayFiltros
                                ? 'Ningún acceso coincide con el filtro.'
                                : 'Todavía no hay accesos registrados.'
                        }
                    />
                </section>

                <PaginationFooter
                    pagination={events}
                    singular="acceso"
                    plural="accesos"
                />
            </div>
        </>
    );
}
