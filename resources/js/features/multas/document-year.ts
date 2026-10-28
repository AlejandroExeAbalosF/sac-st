/**
 * El año que lleva el número de una resolución o un acta, para avisar
 * mientras se escribe si no coincide con su fecha.
 *
 * Es **solo presentación**: la lectura que vale es la de
 * `App\Modules\Multas\Support\FineDocumentNumber` y `TwoDigitYear` en el
 * servidor. Acá se replica para dar el aviso en el momento, no para decidir
 * nada: no coincidir no bloquea —una resolución numerada a fin de año puede
 * firmarse en enero—.
 */
export const NUMERO =
    /^\s*(?:(LAB|H\s*Y\s*S|HYS)\b)?\s*(?:N\s*[°º]?|Nro\.?)?\s*(\d{1,9})\s*(?:\/\s*(\d{2}|\d{4}))?\s*$/iu;

/** Un año de dos cifras, en el siglo que lo deja más cerca del de referencia. */
export function expandYear(year: number, reference: number): number {
    if (year >= 100) {
        return year;
    }

    const siglo = Math.floor(reference / 100) * 100;

    return [siglo - 100 + year, siglo + year, siglo + 100 + year].reduce(
        (cerca, candidato) =>
            Math.abs(candidato - reference) < Math.abs(cerca - reference)
                ? candidato
                : cerca,
    );
}

/**
 * El aviso cuando el año del número no es el de la fecha del papel, o
 * `null`. Sin fecha propia no se compara: un acta suele ser del año
 * anterior a su resolución y avisarlo siempre sería ruido.
 */
export function documentYearWarning(
    text: string,
    issuedOn: string,
): string | null {
    const fecha = /^(\d{4})-\d{2}-\d{2}$/.exec(issuedOn);
    const numero = NUMERO.exec(text);

    if (!fecha || !numero?.[3]) {
        return null;
    }

    const anioDeLaFecha = Number(fecha[1]);
    const anioDelNumero = expandYear(Number(numero[3]), anioDeLaFecha);

    return anioDelNumero === anioDeLaFecha
        ? null
        : `El número dice ${anioDelNumero} y la fecha es de ${anioDeLaFecha}: revisá que esté bien. Se puede guardar igual.`;
}
