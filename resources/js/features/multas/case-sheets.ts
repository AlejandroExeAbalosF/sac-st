type Documento = App.Modules.Multas.Data.FineDocumentData;
type Multa = App.Modules.Multas.Data.FineCaseFineData;

export type ActSheet = { key: string; documents: Documento[]; fines: Multa[] };
export type ResolutionSheet = {
    key: string;
    documents: Documento[];
    acts: ActSheet[];
};

const keyOf = (documents: Documento[]) =>
    documents.map(({ id }) => id).join(',');

/**
 * Agrupa por conjuntos exactos de documentos de origen, nunca por su rótulo.
 * Una multa aparece una sola vez aunque tenga varias actas o resoluciones.
 * Compartir expediente no establece por sí solo un vínculo entre papeles.
 */
export function caseSheets(fines: Multa[], documents: Documento[]) {
    const resolutions = new Map<string, ResolutionSheet>();
    const linked = new Set<number>();

    for (const fine of fines) {
        fine.documents.forEach(({ id }) => linked.add(id));
        const origin = fine.documents
            .filter(({ roles }) => roles.includes('origin'))
            .sort((a, b) => a.id - b.id);
        const resolutionDocuments = origin.filter(
            ({ kind }) => kind === 'resolution',
        );
        const actDocuments = origin.filter(({ kind }) => kind === 'act');
        const resolutionKey = keyOf(resolutionDocuments);
        let sheet = resolutions.get(resolutionKey);

        if (!sheet) {
            sheet = {
                key: resolutionKey,
                documents: resolutionDocuments,
                acts: [],
            };
            resolutions.set(resolutionKey, sheet);
        }

        const actKey = keyOf(actDocuments);
        let act = sheet.acts.find(({ key }) => key === actKey);

        if (!act) {
            act = { key: actKey, documents: actDocuments, fines: [] };
            sheet.acts.push(act);
        }

        act.fines.push(fine);
    }

    return {
        resolutions: [...resolutions.values()],
        unlinked: documents.filter(({ id }) => !linked.has(id)),
    };
}
