import { DOCUMENT_KIND } from '@/features/multas/labels';

type Documento = Pick<
    App.Modules.Multas.Data.FineDocumentData,
    'id' | 'kind' | 'roles'
>;
type Kind = App.Modules.Multas.Enums.FineDocumentKind;
type Rol = App.Modules.Multas.Enums.FineDocumentRole;

/**
 * Lo que «Vincular un documento» ofrece para cada papel: solo lo que
 * `LinkFineDocument` acepta.
 */

/** Como origen ya no entra otra resolución: la multa tiene la suya. */
export function blocksResolution(vinculados: Documento[], rol: Rol): boolean {
    return (
        rol === 'origin' &&
        vinculados.some(
            (d) => d.kind === 'resolution' && d.roles.includes('origin'),
        )
    );
}

/**
 * Los documentos del expediente que pueden vincularse con ese papel: ni los
 * que ya lo cumplen en la multa, ni una segunda resolución de origen.
 */
export function linkableDocuments<T extends Documento>(
    documentos: T[],
    vinculados: Documento[],
    rol: Rol,
): T[] {
    const sinResolucion = blocksResolution(vinculados, rol);

    return documentos.filter(
        (d) =>
            !vinculados.some((v) => v.id === d.id && v.roles.includes(rol)) &&
            !(sinResolucion && d.kind === 'resolution'),
    );
}

/** Los tipos de un documento nuevo que pueden vincularse con ese papel. */
export function linkableKinds(vinculados: Documento[], rol: Rol): Kind[] {
    const sinResolucion = blocksResolution(vinculados, rol);

    return (Object.keys(DOCUMENT_KIND) as Kind[]).filter(
        (kind) => !(sinResolucion && kind === 'resolution'),
    );
}
