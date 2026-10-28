import { NUMERO } from '@/features/multas/document-year';

type Kind = App.Modules.Multas.Enums.FineDocumentKind;
type ActType = App.Modules.Multas.Enums.FineActType;
type Cargado = Pick<
    App.Modules.Multas.Data.FineDocumentData,
    'id' | 'kind' | 'actType' | 'number' | 'year' | 'label'
>;

/**
 * El documento del expediente que ya tiene el número que se está
 * escribiendo, o `null`.
 *
 * Dentro de un expediente, el mismo tipo con el mismo número y año es el
 * mismo papel: el Excel histórico lo muestra citado por varias multas, a
 * veces escrito distinto («LAB. Nº 05196/11» y «LAB Nº 5196/11»). Por eso se
 * compara el número leído, no el texto.
 *
 * Es **solo un aviso**: no bloquea, y la base no lo impone. El año de dos
 * cifras se compara por sus dos últimas cifras, para no depender del siglo
 * que el servidor le asignó. Un acta compara además su tipo, si se sabe:
 * «LAB 18505/14» y «HYS 18505/14» son actas distintas.
 */
export function findLoadedDocument<T extends Cargado>(
    documentos: T[],
    kind: Kind,
    text: string,
    actType: ActType | null = null,
): T | null {
    if (kind === 'other') {
        return null;
    }

    const leido = NUMERO.exec(text);

    if (!leido) {
        return null;
    }

    const numero = Number(leido[2]);
    const anio = leido[3] ?? null;
    const tipoEscrito = leido[1]
        ? leido[1].replace(/\s+/g, '').toUpperCase() === 'LAB'
            ? 'LAB'
            : 'HYS'
        : null;
    const tipo = tipoEscrito ?? actType;

    return (
        documentos.find(
            (d) =>
                d.kind === kind &&
                d.number === numero &&
                (anio === null
                    ? d.year === null
                    : anio.length === 2
                      ? d.year !== null && d.year % 100 === Number(anio)
                      : d.year === Number(anio)) &&
                (kind !== 'act' ||
                    tipo === null ||
                    d.actType === null ||
                    d.actType === tipo),
        ) ?? null
    );
}
