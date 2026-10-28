import { fullYear } from '@/lib/two-digit-year';

/**
 * Una fecha pegada, en el `AAAA-MM-DD` que entiende `<input type="date">`.
 *
 * El campo nativo no acepta texto pegado, y lo que se pega viene como lo
 * escribe el papel o la planilla: `06/06/2023`, `6/6/23`, `06-06-2023`,
 * `06.06.2023` o ya en `2023-06-06`. Primero va el día —el orden argentino—;
 * el año con cuatro cifras al principio es el ISO. Una hora detrás
 * (`06/06/2023 10:30`) se ignora.
 *
 * Un año de dos cifras es del siglo en curso hasta el año próximo, y del
 * anterior por encima, como en el servidor (`TwoDigitYear`): el siglo sale
 * del año de hoy, no de un 2000 fijo. Una fecha que no existe —`31/02/2023`—
 * devuelve `null`, y el pegado se deja pasar como si nada.
 */
export function parsePastedDate(
    text: string,
    now: Date = new Date(),
): string | null {
    const limpio = text.trim().split(/\s+/)[0] ?? '';

    const iso = /^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$/.exec(limpio);
    const local = /^(\d{1,2})[-/.](\d{1,2})[-/.](\d{2}|\d{4})$/.exec(limpio);

    let anio: number;
    let mes: number;
    let dia: number;

    if (iso) {
        [anio, mes, dia] = [Number(iso[1]), Number(iso[2]), Number(iso[3])];
    } else if (local) {
        dia = Number(local[1]);
        mes = Number(local[2]);
        anio =
            local[3].length === 2
                ? fullYear(Number(local[3]), null, now)
                : Number(local[3]);
    } else {
        return null;
    }

    // Se arma en UTC para que la zona horaria no corra el día.
    const fecha = new Date(Date.UTC(anio, mes - 1, dia));

    if (
        fecha.getUTCFullYear() !== anio ||
        fecha.getUTCMonth() !== mes - 1 ||
        fecha.getUTCDate() !== dia
    ) {
        return null;
    }

    return `${String(anio).padStart(4, '0')}-${String(mes).padStart(2, '0')}-${String(dia).padStart(2, '0')}`;
}

/** `2023-06-06` → `06062023`: las cifras que muestra el campo, sin barras. */
export function isoToDigits(iso: string): string {
    const partes = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);

    return partes ? `${partes[3]}${partes[2]}${partes[1]}` : '';
}

/**
 * `06062023` → `2023-06-06`. Vacío mientras faltan cifras o si la fecha no
 * existe: el formulario no recibe una fecha a medias.
 */
export function digitsToIso(digits: string): string {
    if (!/^\d{8}$/.test(digits)) {
        return '';
    }

    return (
        parsePastedDate(
            `${digits.slice(0, 2)}/${digits.slice(2, 4)}/${digits.slice(4)}`,
        ) ?? ''
    );
}
