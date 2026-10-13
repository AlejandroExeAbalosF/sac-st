import { Head, router } from '@inertiajs/react';
import { Lock, ShieldCheck } from 'lucide-react';
import { useMemo, useState } from 'react';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import ConfirmRolePermissionsDialog from '@/features/configuracion/components/confirm-role-permissions-dialog';
import DiscardChangesDialog from '@/features/configuracion/components/discard-changes-dialog';
import PermissionMatrix, {
    MarcaSensible,
} from '@/features/configuracion/components/permission-matrix';
import {
    calcularCambios,
    cantidadDeCambios,
    plural,
} from '@/features/configuracion/role-permissions';
import type { GrupoDePermisos } from '@/features/configuracion/role-permissions';
import { useUnsavedChangesGuard } from '@/hooks/use-unsaved-changes-guard';
import { cn } from '@/lib/utils';
import { update } from '@/routes/configuracion/roles';

type Rol = App.Modules.Shared.Data.RoleData;

type Props = {
    roles: Rol[];
    permissionGroups: GrupoDePermisos[];
};

const SIN_CAMBIOS = { otorgados: [], retirados: [] };

/**
 * La matriz de rol × permiso.
 *
 * El catálogo de permisos no se edita acá: un permiso existe porque hay una
 * ruta que lo exige, y eso lo declara `PermissionCatalog` junto con qué hace
 * cada uno. Lo que se decide en esta pantalla es **quién lo tiene**, que es
 * una decisión del área y no del código.
 *
 * El borrador vive en esta página y no en la matriz: es lo que permite
 * frenar el cambio de rol —o la salida de la pantalla— cuando hay casillas
 * sin guardar. Antes cada rol tenía su propio estado y elegir otro lo
 * descartaba en silencio.
 */
export default function Roles({ roles, permissionGroups }: Props) {
    const [seleccionado, setSeleccionado] = useState<string>(
        () => roles.find((rol) => rol.editable)?.name ?? roles[0]?.name ?? '',
    );
    const rol = roles.find((r) => r.name === seleccionado) ?? null;

    const [borrador, setBorrador] = useState<Set<string>>(
        () => new Set(rol?.permissions ?? []),
    );
    const guardados = useMemo(
        () => new Set(rol?.permissions ?? []),
        [rol?.permissions],
    );

    const cambios = useMemo(
        () =>
            rol?.editable
                ? calcularCambios(permissionGroups, guardados, borrador)
                : SIN_CAMBIOS,
        [rol?.editable, permissionGroups, guardados, borrador],
    );
    const pendientes = cantidadDeCambios(cambios);
    const sucio = pendientes > 0;

    const guardia = useUnsavedChangesGuard(sucio);
    const [rolEnEspera, setRolEnEspera] = useState<string | null>(null);
    const [confirmando, setConfirmando] = useState(false);
    const [guardando, setGuardando] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const totalDePermisos = permissionGroups.reduce(
        (total, grupo) => total + grupo.permissions.length,
        0,
    );
    const otorgadosEnPantalla = permissionGroups.reduce(
        (total, grupo) =>
            total +
            grupo.permissions.filter((p) => borrador.has(p.name)).length,
        0,
    );

    const abrirRol = (nombre: string) => {
        setSeleccionado(nombre);
        setBorrador(
            new Set(roles.find((r) => r.name === nombre)?.permissions ?? []),
        );
        setError(null);
    };

    const elegirRol = (nombre: string) => {
        if (nombre === seleccionado) {
            return;
        }

        if (sucio) {
            setRolEnEspera(nombre);

            return;
        }

        abrirRol(nombre);
    };

    const alternar = (permiso: string, marcado: boolean) =>
        setBorrador((previos) => {
            const siguiente = new Set(previos);

            if (marcado) {
                siguiente.add(permiso);
            } else {
                siguiente.delete(permiso);
            }

            return siguiente;
        });

    const guardar = () => {
        if (rol === null) {
            return;
        }

        setGuardando(true);
        setError(null);

        guardia.sinGuardia(() =>
            router.put(
                update(rol.id).url,
                { permissions: [...borrador] },
                {
                    preserveScroll: true,
                    // Sin esto la página se remonta al volver y la
                    // selección salta al primer rol editable.
                    preserveState: true,
                    onSuccess: () => setConfirmando(false),
                    onError: (errores) =>
                        setError(
                            Object.values(errores)[0] ??
                                'El servidor rechazó el cambio.',
                        ),
                    onFinish: () => setGuardando(false),
                },
            ),
        );
    };

    const detalleDescarte =
        rol === null
            ? ''
            : `${plural(pendientes, 'cambio', 'cambios')} en los permisos de ${rol.name} ${pendientes === 1 ? 'se va a perder' : 'se van a perder'}` +
              (rolEnEspera !== null ? ` si pasás a ${rolEnEspera}.` : '.');

    return (
        <>
            <Head title="Roles y permisos" />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Configuración"
                    title="Roles y permisos"
                    description="Qué puede hacer cada rol dentro del sistema. Los cambios se aplican en el acto a todos los usuarios del rol."
                />

                <div className="grid gap-6 lg:grid-cols-[minmax(0,18rem)_minmax(0,1fr)]">
                    <nav
                        className="flex flex-col gap-2 lg:sticky lg:top-20 lg:self-start"
                        aria-label="Roles del sistema"
                    >
                        {roles.map((item) => {
                            const activo = item.name === seleccionado;

                            return (
                                <button
                                    key={item.name}
                                    type="button"
                                    onClick={() => elegirRol(item.name)}
                                    aria-current={activo ? 'true' : undefined}
                                    className={cn(
                                        'rounded-lg border bg-card p-4 text-left transition-[background-color,border-color,box-shadow] duration-200 motion-reduce:transition-none',
                                        activo
                                            ? 'border-primary shadow-raised'
                                            : 'hover:bg-accent/30',
                                    )}
                                >
                                    <span className="flex items-center gap-2 text-sm font-semibold capitalize">
                                        {item.editable ? (
                                            <ShieldCheck
                                                className="size-4 text-muted-foreground"
                                                aria-hidden="true"
                                            />
                                        ) : (
                                            <Lock
                                                className="size-4 text-muted-foreground"
                                                aria-hidden="true"
                                            />
                                        )}
                                        {item.name}
                                        {activo && sucio && (
                                            <span
                                                className="ml-auto inline-flex animate-in items-center gap-1.5 text-xs font-medium text-warning-strong normal-case fade-in-0 motion-reduce:animate-none"
                                                title="Hay cambios sin guardar"
                                            >
                                                <span
                                                    className="size-2 rounded-full bg-warning"
                                                    aria-hidden="true"
                                                />
                                                Sin guardar
                                            </span>
                                        )}
                                    </span>
                                    <span className="mt-1 block text-xs text-muted-foreground">
                                        {item.description}
                                    </span>
                                    <span className="mt-2 block text-xs text-muted-foreground">
                                        {plural(
                                            item.usersCount,
                                            'usuario',
                                            'usuarios',
                                        )}
                                        {item.editable
                                            ? ` · ${plural(item.permissions.length, 'permiso', 'permisos')}`
                                            : ' · todos los permisos'}
                                    </span>
                                </button>
                            );
                        })}
                    </nav>

                    {rol && (
                        <section
                            key={rol.name}
                            className="animate-in rounded-lg border bg-card shadow-raised duration-200 fade-in-0 slide-in-from-bottom-1 motion-reduce:animate-none"
                            aria-labelledby="rol-seleccionado"
                        >
                            <header className="border-b px-5 py-3.5">
                                <h2
                                    id="rol-seleccionado"
                                    className="text-sm font-semibold capitalize"
                                >
                                    {rol.name}
                                </h2>
                                <p className="text-xs text-muted-foreground tabular-nums">
                                    {rol.editable
                                        ? `${otorgadosEnPantalla} de ${totalDePermisos} permisos`
                                        : 'Recibe todos los permisos por diseño, incluidos los que agreguen las fases futuras.'}
                                </p>
                            </header>

                            {rol.lockedReason !== null ? (
                                <p className="flex items-start gap-2 border-b bg-muted/30 px-5 py-3 text-xs text-muted-foreground">
                                    <Lock
                                        className="mt-0.5 size-3.5 shrink-0"
                                        aria-hidden="true"
                                    />
                                    {rol.lockedReason}
                                </p>
                            ) : (
                                <p className="flex flex-wrap items-center gap-x-2 gap-y-1 border-b bg-muted/30 px-5 py-2.5 text-xs text-muted-foreground">
                                    <MarcaSensible />
                                    marca los permisos que sacan dinero del
                                    organismo, deshacen algo ya registrado,
                                    mueven saldos sin dinero de por medio,
                                    cambian la configuración o abren datos
                                    reservados.
                                </p>
                            )}

                            <PermissionMatrix
                                grupos={permissionGroups}
                                guardados={guardados}
                                borrador={borrador}
                                editable={rol.editable}
                                ocupado={guardando}
                                onAlternar={alternar}
                            />

                            {rol.editable && (
                                <footer className="sticky bottom-0 z-10 flex flex-wrap items-center gap-3 rounded-b-lg border-t bg-card/95 px-5 py-3 backdrop-blur-sm">
                                    <p
                                        className="flex items-center gap-2 text-sm"
                                        aria-live="polite"
                                    >
                                        {sucio ? (
                                            <>
                                                <span
                                                    className="size-2 rounded-full bg-warning motion-safe:animate-pulse"
                                                    aria-hidden="true"
                                                />
                                                <span className="font-medium whitespace-nowrap">
                                                    {plural(
                                                        pendientes,
                                                        'cambio sin guardar',
                                                        'cambios sin guardar',
                                                    )}
                                                </span>
                                                {/* En celular el detalle no entra en un renglón; está completo en el resumen previo al guardado. */}
                                                <span className="hidden text-muted-foreground tabular-nums sm:inline">
                                                    ·{' '}
                                                    {plural(
                                                        cambios.otorgados
                                                            .length,
                                                        'se otorga',
                                                        'se otorgan',
                                                    )}{' '}
                                                    ·{' '}
                                                    {plural(
                                                        cambios.retirados
                                                            .length,
                                                        'se retira',
                                                        'se retiran',
                                                    )}
                                                </span>
                                            </>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                Sin cambios pendientes
                                            </span>
                                        )}
                                    </p>

                                    <div className="ml-auto flex gap-2">
                                        {sucio && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                className="animate-in fade-in-0 motion-reduce:animate-none"
                                                disabled={guardando}
                                                onClick={() =>
                                                    setBorrador(
                                                        new Set(guardados),
                                                    )
                                                }
                                            >
                                                Descartar
                                            </Button>
                                        )}
                                        <Button
                                            type="button"
                                            disabled={!sucio || guardando}
                                            onClick={() => {
                                                setError(null);
                                                setConfirmando(true);
                                            }}
                                            data-test="save-role-permissions"
                                        >
                                            Revisar y guardar
                                        </Button>
                                    </div>
                                </footer>
                            )}
                        </section>
                    )}
                </div>
            </div>

            {rol && (
                <ConfirmRolePermissionsDialog
                    abierto={confirmando}
                    rol={rol.name}
                    usuarios={rol.usersCount}
                    cambios={cambios}
                    guardando={guardando}
                    error={error}
                    onConfirmar={guardar}
                    onCerrar={() => setConfirmando(false)}
                />
            )}

            <DiscardChangesDialog
                abierto={rolEnEspera !== null || guardia.pendiente !== null}
                detalle={detalleDescarte}
                onDescartar={() => {
                    if (rolEnEspera !== null) {
                        abrirRol(rolEnEspera);
                        setRolEnEspera(null);
                    } else {
                        guardia.continuar();
                    }
                }}
                onSeguir={() => {
                    setRolEnEspera(null);
                    guardia.cancelar();
                }}
            />
        </>
    );
}
