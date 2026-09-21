import { Head, router, usePage } from '@inertiajs/react';
import { KeyRound, Pencil, Search, UserPlus } from 'lucide-react';
import { useState } from 'react';
import FormError from '@/components/form-error';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { TemporaryPassword } from '@/features/configuracion/components/temporary-password-dialog';
import TemporaryPasswordDialog from '@/features/configuracion/components/temporary-password-dialog';
import type { UserFormMode } from '@/features/configuracion/components/user-form-dialog';
import UserFormDialog from '@/features/configuracion/components/user-form-dialog';
import { dateTime } from '@/lib/format';
import { contrasena, estado, index } from '@/routes/configuracion/usuarios';
import type { Auth } from '@/types';

type Usuario = App.Modules.Shared.Data.UserListItemData;

type Props = {
    users: Usuario[];
    roles: Record<string, string>;
    filters: { buscar: string | null };
    can: {
        create: boolean;
        edit: boolean;
        deactivate: boolean;
        resetPassword: boolean;
    };
};

/**
 * Los usuarios del sistema.
 *
 * Hasta que existió esta pantalla, dar de alta a alguien exigía `tinker`.
 *
 * No hay botón de borrar y no es un olvido: un usuario que registró una
 * recepción o validó un egreso tiene que seguir siendo identificable para
 * siempre. Lo que se hace es desactivarlo, que le impide entrar sin tocar
 * una línea de su historial.
 */
export default function Usuarios({ users, roles, filters, can }: Props) {
    const { auth, flash, errors } = usePage().props as unknown as {
        auth: Auth;
        errors?: Record<string, string>;
        flash?: {
            temporaryPassword?: TemporaryPassword;
        };
    };

    const [termino, setTermino] = useState(filters.buscar ?? '');
    const [formulario, setFormulario] = useState<UserFormMode | null>(null);

    /*
     * La contraseña llega en un flash de una sola vista, así que el diálogo
     * se deriva de ella en vez de copiarla a estado: lo único que hace
     * falta recordar es cuál ya se descartó. Copiarla obligaría a un efecto
     * que llama a setState, que es exactamente el patrón de renders en
     * cascada que React desaconseja.
     */
    const [descartada, setDescartada] = useState<string | null>(null);
    const credenciales =
        flash?.temporaryPassword &&
        flash.temporaryPassword.password !== descartada
            ? flash.temporaryPassword
            : null;

    const buscar = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(
            index().url,
            { buscar: termino },
            { preserveState: true, replace: true },
        );
    };

    const cambiarEstado = (usuario: Usuario) =>
        router.patch(
            estado(usuario.id).url,
            { isActive: !usuario.isActive },
            { preserveScroll: true },
        );

    const restablecer = (usuario: Usuario) =>
        router.post(contrasena(usuario.id).url, {}, { preserveScroll: true });

    return (
        <>
            <Head title="Usuarios" />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Configuración"
                    title="Usuarios"
                    description="Quién puede entrar al sistema y con qué alcance."
                    actions={
                        can.create && (
                            <Button
                                onClick={() =>
                                    setFormulario({ kind: 'create' })
                                }
                                data-test="new-user-button"
                            >
                                <UserPlus className="size-4" />
                                Nuevo usuario
                            </Button>
                        )
                    }
                />

                {/*
                 * Los rechazos del servidor —desactivarse a uno mismo, al
                 * ultimo administrador, restablecerse la propia clave— no
                 * pertenecen a ningun campo: son de la accion que se acaba de
                 * intentar. Sin esto llegaban y no se mostraban en ningun
                 * lado, y el operador solo veia que no habia pasado nada.
                 */}
                <FormError
                    message={errors?.isActive ?? errors?.resetPassword}
                />

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
                            aria-label="Buscar por nombre, usuario, DNI o correo"
                            placeholder="Nombre, usuario, DNI o correo…"
                            className="h-9 w-full rounded-lg border bg-card pr-3 pl-9 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring"
                        />
                    </div>
                    <Button type="submit" variant="secondary">
                        Buscar
                    </Button>
                </form>

                <div className="overflow-x-auto rounded-lg border bg-card shadow-raised">
                    <table className="w-full border-collapse text-left">
                        <caption className="sr-only">
                            Usuarios del sistema
                        </caption>
                        <thead>
                            <tr className="border-b bg-muted text-xs tracking-wide text-field-label uppercase">
                                <th
                                    scope="col"
                                    className="px-4 py-2.5 font-medium"
                                >
                                    Nombre
                                </th>
                                <th
                                    scope="col"
                                    className="py-2.5 pr-5 font-medium"
                                >
                                    Usuario
                                </th>
                                <th
                                    scope="col"
                                    className="py-2.5 pr-5 font-medium"
                                >
                                    DNI
                                </th>
                                <th
                                    scope="col"
                                    className="py-2.5 pr-5 font-medium"
                                >
                                    Rol
                                </th>
                                <th
                                    scope="col"
                                    className="py-2.5 pr-5 font-medium"
                                >
                                    Último ingreso
                                </th>
                                <th
                                    scope="col"
                                    className="py-2.5 pr-5 font-medium"
                                >
                                    Estado
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
                            {users.map((usuario) => {
                                /*
                                 * La propia fila no ofrece ni restablecer la
                                 * clave ni desactivarse: las dos terminan
                                 * dejando al administrador afuera de su propio
                                 * sistema, y el servidor las rechaza. Se
                                 * muestran trabadas y no ocultas, para que se
                                 * vea que la accion existe y por que no es
                                 * para uno mismo.
                                 */
                                const esUnoMismo = usuario.id === auth.user.id;

                                return (
                                    <tr
                                        key={usuario.id}
                                        className="border-b transition-colors focus-within:bg-accent/30 hover:bg-accent/30"
                                    >
                                        <td className="px-4 py-3">
                                            <p className="text-sm">
                                                {usuario.name}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {usuario.position ??
                                                    'Sin cargo'}
                                            </p>
                                        </td>

                                        <td className="py-3 pr-5 font-mono text-sm">
                                            {usuario.username}
                                        </td>

                                        <td className="py-3 pr-5 font-mono text-sm tabular-nums">
                                            {usuario.documentNumber}
                                        </td>

                                        <td className="py-3 pr-5 text-sm text-muted-foreground capitalize">
                                            {usuario.role ?? '—'}
                                        </td>

                                        <td className="py-3 pr-5 font-mono text-xs text-muted-foreground tabular-nums">
                                            {usuario.lastLoginAt === null
                                                ? 'Nunca entró'
                                                : dateTime(usuario.lastLoginAt)}
                                        </td>

                                        <td className="py-3 pr-5">
                                            <div className="flex flex-col items-start gap-1">
                                                <StatusBadge
                                                    label={
                                                        usuario.isActive
                                                            ? 'Activo'
                                                            : 'Inactivo'
                                                    }
                                                    tone={
                                                        usuario.isActive
                                                            ? 'done'
                                                            : 'neutral'
                                                    }
                                                />
                                                {usuario.mustChangePassword && (
                                                    <StatusBadge
                                                        label="Clave provisoria"
                                                        tone="action"
                                                    />
                                                )}
                                            </div>
                                        </td>

                                        <td className="py-3 pr-4">
                                            <div className="flex items-center justify-end gap-1">
                                                {can.edit && (
                                                    <Tooltip>
                                                        <TooltipTrigger asChild>
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                aria-label={`Corregir la ficha de ${usuario.name}`}
                                                                onClick={() =>
                                                                    setFormulario(
                                                                        {
                                                                            kind: 'edit',
                                                                            user: usuario,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                <Pencil
                                                                    className="size-4"
                                                                    aria-hidden="true"
                                                                />
                                                            </Button>
                                                        </TooltipTrigger>
                                                        <TooltipContent>
                                                            Corregir la ficha
                                                        </TooltipContent>
                                                    </Tooltip>
                                                )}

                                                {can.resetPassword && (
                                                    <Tooltip>
                                                        <TooltipTrigger asChild>
                                                            <span className="inline-flex">
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    disabled={
                                                                        esUnoMismo
                                                                    }
                                                                    aria-label={`Restablecer la contraseña de ${usuario.name}`}
                                                                    onClick={() =>
                                                                        restablecer(
                                                                            usuario,
                                                                        )
                                                                    }
                                                                >
                                                                    <KeyRound
                                                                        className="size-4"
                                                                        aria-hidden="true"
                                                                    />
                                                                </Button>
                                                            </span>
                                                        </TooltipTrigger>
                                                        <TooltipContent>
                                                            {esUnoMismo
                                                                ? 'Tu contraseña se cambia desde Mi cuenta › Seguridad'
                                                                : 'Restablecer la contraseña y cerrar sus sesiones'}
                                                        </TooltipContent>
                                                    </Tooltip>
                                                )}

                                                {can.deactivate && (
                                                    <Tooltip>
                                                        <TooltipTrigger asChild>
                                                            <span className="ml-1 inline-flex">
                                                                <Switch
                                                                    checked={
                                                                        usuario.isActive
                                                                    }
                                                                    disabled={
                                                                        esUnoMismo
                                                                    }
                                                                    onCheckedChange={() =>
                                                                        cambiarEstado(
                                                                            usuario,
                                                                        )
                                                                    }
                                                                    aria-label={`${usuario.isActive ? 'Desactivar' : 'Activar'} a ${usuario.name}`}
                                                                />
                                                            </span>
                                                        </TooltipTrigger>
                                                        <TooltipContent>
                                                            {esUnoMismo
                                                                ? 'No podés desactivar tu propio usuario'
                                                                : usuario.isActive
                                                                  ? 'Desactivar: no podrá entrar y se cierran sus sesiones'
                                                                  : 'Activar: vuelve a poder entrar'}
                                                        </TooltipContent>
                                                    </Tooltip>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>

                    {users.length === 0 && (
                        <p className="px-5 py-12 text-center text-sm text-muted-foreground">
                            {filters.buscar
                                ? `Ningún usuario coincide con «${filters.buscar}».`
                                : 'Todavía no hay usuarios cargados.'}
                        </p>
                    )}
                </div>

                <p className="text-xs text-muted-foreground">
                    {users.length} {users.length === 1 ? 'usuario' : 'usuarios'}
                </p>
            </div>

            <UserFormDialog
                mode={formulario}
                roles={roles}
                onClose={() => setFormulario(null)}
            />

            <TemporaryPasswordDialog
                credentials={credenciales}
                onClose={() => setDescartada(credenciales?.password ?? null)}
            />
        </>
    );
}
