import { Head, router } from '@inertiajs/react';
import { Lock, ShieldCheck } from 'lucide-react';
import { useMemo, useState } from 'react';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';
import { update } from '@/routes/configuracion/roles';

type Rol = App.Modules.Shared.Data.RoleData;
type Grupo = App.Modules.Shared.Data.PermissionGroupData;

type Props = {
    roles: Rol[];
    permissionGroups: Grupo[];
};

/**
 * La matriz de rol × permiso.
 *
 * El catálogo de permisos no se edita acá: un permiso existe porque hay una
 * ruta que lo exige, y eso lo declara `RolesAndPermissionsSeeder`. Lo que se
 * decide en esta pantalla es **quién lo tiene**, que es una decisión del
 * área y no del código.
 *
 * Los nombres se muestran tal cual —`caja.abrir-saldo-inicial`, no «Abrir el
 * saldo inicial»— a propósito: son literalmente los mismos que se leen en
 * `routes/modules/*.php`, y esa correspondencia es lo que permite auditar la
 * matriz contra las rutas sin un diccionario en el medio.
 */
export default function Roles({ roles, permissionGroups }: Props) {
    const [seleccionado, setSeleccionado] = useState<string>(
        roles.find((rol) => rol.editable)?.name ?? roles[0]?.name ?? '',
    );

    const rol = roles.find((r) => r.name === seleccionado) ?? null;

    return (
        <>
            <Head title="Roles y permisos" />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Configuración"
                    title="Roles y permisos"
                    description="Qué puede hacer cada rol dentro del sistema."
                />

                <div className="grid gap-6 lg:grid-cols-[minmax(0,18rem)_minmax(0,1fr)]">
                    <nav
                        className="flex flex-col gap-2"
                        aria-label="Roles del sistema"
                    >
                        {roles.map((item) => (
                            <button
                                key={item.name}
                                type="button"
                                onClick={() => setSeleccionado(item.name)}
                                aria-current={
                                    item.name === seleccionado
                                        ? 'true'
                                        : undefined
                                }
                                className={cn(
                                    'rounded-lg border bg-card p-4 text-left transition-colors',
                                    item.name === seleccionado
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
                                </span>
                                <span className="mt-1 block text-xs text-muted-foreground">
                                    {item.description}
                                </span>
                                <span className="mt-2 block text-xs text-muted-foreground">
                                    {item.usersCount === 1
                                        ? '1 usuario'
                                        : `${item.usersCount} usuarios`}
                                    {item.editable &&
                                        ` · ${item.permissions.length} permisos`}
                                </span>
                            </button>
                        ))}
                    </nav>

                    {rol && (
                        <MatrizDelRol
                            key={rol.name}
                            rol={rol}
                            grupos={permissionGroups}
                        />
                    )}
                </div>
            </div>
        </>
    );
}

/**
 * Los permisos de un rol, agrupados por módulo.
 *
 * El estado de los casilleros es local hasta que se guarda: la matriz tiene
 * sesenta filas y mandar una petición por cada clic convertiría una
 * revisión de permisos en sesenta escrituras auditadas.
 */
function MatrizDelRol({ rol, grupos }: { rol: Rol; grupos: Grupo[] }) {
    const [otorgados, setOtorgados] = useState<Set<string>>(
        () => new Set(rol.permissions),
    );
    const [guardando, setGuardando] = useState(false);

    // Al cambiar de rol el componente se remonta —la key es el nombre— así
    // que el estado arranca del rol correcto sin sincronizar nada.
    const sucio = useMemo(() => {
        const original = new Set(rol.permissions);

        return (
            original.size !== otorgados.size ||
            [...otorgados].some((permiso) => !original.has(permiso))
        );
    }, [otorgados, rol.permissions]);

    const alternar = (permiso: string, marcado: boolean) =>
        setOtorgados((previos) => {
            const siguiente = new Set(previos);

            if (marcado) {
                siguiente.add(permiso);
            } else {
                siguiente.delete(permiso);
            }

            return siguiente;
        });

    const guardar = () => {
        setGuardando(true);

        router.put(
            update(rol.id).url,
            { permissions: [...otorgados] },
            {
                preserveScroll: true,
                onFinish: () => setGuardando(false),
            },
        );
    };

    return (
        <section className="rounded-lg border bg-card shadow-raised">
            <header className="flex flex-wrap items-center gap-3 border-b px-5 py-3.5">
                <div className="min-w-0">
                    <h2 className="text-sm font-semibold capitalize">
                        {rol.name}
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        {rol.editable
                            ? `${otorgados.size} de ${grupos.reduce((total, grupo) => total + grupo.permissions.length, 0)} permisos`
                            : 'Recibe todos los permisos por diseño, incluidos los que agreguen las fases futuras.'}
                    </p>
                </div>

                {rol.editable && (
                    <Button
                        className="ml-auto"
                        disabled={!sucio || guardando}
                        onClick={guardar}
                        data-test="save-role-permissions"
                    >
                        {guardando ? 'Guardando…' : 'Guardar permisos'}
                    </Button>
                )}
            </header>

            {!rol.editable && (
                <p className="flex items-start gap-2 border-b bg-muted/30 px-5 py-3 text-xs text-muted-foreground">
                    <Lock
                        className="mt-0.5 size-3.5 shrink-0"
                        aria-hidden="true"
                    />
                    El administrador no pasa por esta matriz: el sistema le
                    concede todo antes de consultarla. Desmarcarle un casillero
                    daría la impresión de haberlo restringido sin haberlo hecho,
                    así que la pantalla no lo permite.
                </p>
            )}

            <div className="divide-y">
                {grupos.map((grupo) => (
                    <fieldset key={grupo.key} className="px-5 py-4">
                        <legend className="text-xs font-semibold tracking-wide text-field-label uppercase">
                            {grupo.label}
                        </legend>

                        <div className="mt-3 grid gap-2 sm:grid-cols-2">
                            {grupo.permissions.map((permiso) => (
                                <label
                                    key={permiso}
                                    className={cn(
                                        'flex items-center gap-2.5 rounded-md px-2 py-1.5 text-sm',
                                        rol.editable
                                            ? 'cursor-pointer hover:bg-accent/30'
                                            : 'opacity-60',
                                    )}
                                >
                                    <Checkbox
                                        checked={
                                            rol.editable
                                                ? otorgados.has(permiso)
                                                : true
                                        }
                                        disabled={!rol.editable}
                                        onCheckedChange={(marcado) =>
                                            alternar(permiso, marcado === true)
                                        }
                                    />
                                    <span className="font-mono text-xs">
                                        {permiso}
                                    </span>
                                </label>
                            ))}
                        </div>
                    </fieldset>
                ))}
            </div>
        </section>
    );
}
