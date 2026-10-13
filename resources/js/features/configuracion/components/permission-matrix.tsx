import { Lock, ShieldAlert } from 'lucide-react';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';
import type { GrupoDePermisos, Permiso } from '../role-permissions';

type Props = {
    grupos: GrupoDePermisos[];
    guardados: ReadonlySet<string>;
    borrador: ReadonlySet<string>;
    /** `false` en los roles que reciben todo por diseño: se ve todo marcado y trabado. */
    editable: boolean;
    /** Mientras se guarda, las casillas no se tocan. */
    ocupado: boolean;
    onAlternar: (permiso: string, marcado: boolean) => void;
};

/**
 * Los permisos de un rol, agrupados por concepto del área.
 *
 * Cada fila dice qué hace el permiso antes que cómo se llama: el rótulo y
 * la descripción son para quien decide, y el nombre técnico queda abajo,
 * chico, para quien coteja la matriz contra las rutas.
 *
 * Lo que cambió respecto de lo guardado se marca en la fila misma —verde lo
 * que se otorga, rojo lo que se retira— para que el resumen previo al
 * guardado no sea la primera vez que alguien ve el alcance de sus clics.
 */
export default function PermissionMatrix({
    grupos,
    guardados,
    borrador,
    editable,
    ocupado,
    onAlternar,
}: Props) {
    return (
        <div className="divide-y">
            {grupos.map((grupo) => {
                const marcados = editable
                    ? grupo.permissions.filter((p) => borrador.has(p.name))
                          .length
                    : grupo.permissions.length;

                return (
                    <fieldset
                        key={grupo.key}
                        className="px-5 py-4"
                        disabled={!editable || ocupado}
                    >
                        <legend className="flex w-full items-baseline gap-2">
                            <span className="text-xs font-semibold tracking-wide text-field-label uppercase">
                                {grupo.label}
                            </span>
                            <span className="text-xs text-muted-foreground tabular-nums">
                                {marcados} de {grupo.permissions.length}
                            </span>
                        </legend>

                        <div className="mt-3 grid gap-1.5 xl:grid-cols-2">
                            {grupo.permissions.map((permiso) => (
                                <FilaDePermiso
                                    key={permiso.name}
                                    permiso={permiso}
                                    marcado={
                                        editable
                                            ? borrador.has(permiso.name)
                                            : true
                                    }
                                    guardado={guardados.has(permiso.name)}
                                    editable={editable}
                                    onAlternar={onAlternar}
                                />
                            ))}
                        </div>
                    </fieldset>
                );
            })}
        </div>
    );
}

function FilaDePermiso({
    permiso,
    marcado,
    guardado,
    editable,
    onAlternar,
}: {
    permiso: Permiso;
    marcado: boolean;
    guardado: boolean;
    editable: boolean;
    onAlternar: (permiso: string, marcado: boolean) => void;
}) {
    // Un permiso que no se delega se ve en todos los roles de la matriz,
    // para que nadie lo busque, pero trabado y diciendo por qué.
    const reservado = editable && !permiso.delegable;
    const habilitado = editable && !reservado;

    const cambio = !editable
        ? null
        : marcado && !guardado
          ? 'otorga'
          : !marcado && guardado
            ? 'retira'
            : null;

    const idDescripcion = `permiso-${permiso.name}-descripcion`;

    return (
        <label
            className={cn(
                'flex items-start gap-3 rounded-md border px-3 py-2.5 transition-colors duration-200 motion-reduce:transition-none',
                cambio === 'otorga' && 'border-success/40 bg-success-soft/60',
                cambio === 'retira' &&
                    'border-destructive/30 bg-destructive-soft/50',
                cambio === null && 'border-transparent',
                habilitado
                    ? cambio === null && 'cursor-pointer hover:bg-accent/30'
                    : 'opacity-60',
            )}
        >
            <Checkbox
                className="mt-0.5"
                checked={marcado}
                disabled={!habilitado}
                aria-describedby={idDescripcion}
                onCheckedChange={(valor) =>
                    onAlternar(permiso.name, valor === true)
                }
            />

            <span className="min-w-0 flex-1">
                <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span className="text-sm font-medium">{permiso.label}</span>
                    {permiso.sensitive && <MarcaSensible />}
                    {cambio !== null && (
                        <span
                            className={cn(
                                'ml-auto animate-in rounded-full px-1.5 py-px text-[0.68rem] font-medium duration-200 fade-in-0 zoom-in-95 motion-reduce:animate-none',
                                cambio === 'otorga'
                                    ? 'bg-success-soft text-success-strong'
                                    : 'bg-destructive-soft text-destructive-strong',
                            )}
                        >
                            {cambio === 'otorga' ? 'Se otorga' : 'Se retira'}
                        </span>
                    )}
                </span>
                <span
                    id={idDescripcion}
                    className="mt-0.5 block text-xs leading-relaxed text-muted-foreground"
                >
                    {permiso.description}
                </span>
                {reservado && (
                    <span className="mt-1 flex items-center gap-1.5 text-xs font-medium text-foreground/80">
                        <Lock className="size-3 shrink-0" aria-hidden="true" />
                        No se delega: lo tiene solo el administrador.
                    </span>
                )}
                <span className="mt-1 block font-mono text-[0.68rem] text-muted-foreground/80">
                    {permiso.name}
                </span>
            </span>
        </label>
    );
}

/**
 * Señala un permiso que pesa distinto: el criterio está escrito en la
 * leyenda de la matriz, una sola vez, en vez de repetirse en cada fila.
 */
export function MarcaSensible() {
    return (
        <span className="inline-flex items-center gap-1 rounded-full bg-warning-soft px-1.5 py-px text-[0.68rem] font-medium text-warning-strong">
            <ShieldAlert className="size-3" aria-hidden="true" />
            Sensible
        </span>
    );
}
