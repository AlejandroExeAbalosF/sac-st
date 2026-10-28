import { businessToday } from '@/lib/format';

/**
 * Un año de dos cifras con su siglo, como `TwoDigitYear::expand` en el
 * servidor: con un año de referencia —la fecha del papel—, el siglo que lo
 * deja más cerca de ella; sin referencia, el siglo en curso hasta el año
 * próximo y el anterior por encima. Un año de cuatro cifras queda igual.
 *
 * Es **solo presentación**: la lectura que vale es la del servidor.
 */
export function fullYear(
    year: number,
    reference: number | null = null,
    now: Date = new Date(),
): number {
    if (year >= 100) {
        return year;
    }

    if (reference !== null) {
        const siglo = Math.floor(reference / 100) * 100;

        return [siglo - 100 + year, siglo + year, siglo + 100 + year].reduce(
            (cerca, candidato) =>
                Math.abs(candidato - reference) < Math.abs(cerca - reference)
                    ? candidato
                    : cerca,
        );
    }

    const hoy = Number(businessToday(now).slice(0, 4));
    const siglo = Math.floor(hoy / 100) * 100;

    return year <= (hoy % 100) + 1 ? siglo + year : siglo - 100 + year;
}
