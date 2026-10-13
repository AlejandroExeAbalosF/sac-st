export type Permiso = App.Modules.Shared.Data.PermissionData;
export type GrupoDePermisos = App.Modules.Shared.Data.PermissionGroupData;

export type CambiosDePermisos = {
    otorgados: Permiso[];
    retirados: Permiso[];
};

/**
 * Lo que cambia entre los permisos guardados de un rol y el borrador.
 *
 * Se recorre el catálogo y no los conjuntos, para que la lista salga en el
 * mismo orden en que se ven las casillas: por grupo y en el orden del
 * circuito, no alfabético ni en el orden de los clics.
 */
export function calcularCambios(
    grupos: GrupoDePermisos[],
    guardados: ReadonlySet<string>,
    borrador: ReadonlySet<string>,
): CambiosDePermisos {
    const otorgados: Permiso[] = [];
    const retirados: Permiso[] = [];

    for (const grupo of grupos) {
        for (const permiso of grupo.permissions) {
            const antes = guardados.has(permiso.name);
            const ahora = borrador.has(permiso.name);

            if (ahora && !antes) {
                otorgados.push(permiso);
            } else if (antes && !ahora) {
                retirados.push(permiso);
            }
        }
    }

    return { otorgados, retirados };
}

export function cantidadDeCambios(cambios: CambiosDePermisos): number {
    return cambios.otorgados.length + cambios.retirados.length;
}

export function plural(
    cantidad: number,
    singular: string,
    plural: string,
): string {
    return `${cantidad} ${cantidad === 1 ? singular : plural}`;
}
