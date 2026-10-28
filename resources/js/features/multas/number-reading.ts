import { NUMERO } from '@/features/multas/document-year';
import { fullYear } from '@/lib/two-digit-year';

type Kind = App.Modules.Multas.Enums.FineDocumentKind;
type ActType = App.Modules.Multas.Enums.FineActType;

/**
 * Cómo va a leer el sistema el número que se está escribiendo, con el año
 * completo: lo que muestra la línea «Se lee como» debajo del campo.
 *
 * El campo guarda el texto tal como figura en el papel; esto solo anticipa
 * la lectura. Si el siglo no es el que corresponde, se escribe el año con
 * cuatro cifras. Es **solo presentación**: replica `FineCaseNumber`,
 * `FineDocumentNumber` y `FineDocument::label()` del servidor, que es el
 * que decide.
 */

const EXPEDIENTE =
    /^\s*(\d{1,9})\s*\/\s*(\d{2}|\d{4})\s*(?:-\s*([0-9A-Za-z]{1,10}))?\s*$/;

/** `169607/24-0` → `169607/2024-0`. Sin fecha: el siglo sale del año de hoy. */
export function readCaseNumber(
    text: string,
    now: Date = new Date(),
): string | null {
    const partes = EXPEDIENTE.exec(text);

    if (!partes) {
        return null;
    }

    const anio = fullYear(Number(partes[2]), null, now);

    return `${Number(partes[1])}/${anio}${partes[3] ? `-${partes[3]}` : ''}`;
}

/**
 * `N°0649/26` → `Resolución 649/2026`; `HYS N°1025288/25` →
 * `Acta HYS 1025288/2025`. El siglo sale de la fecha del papel o, sin ella,
 * de la de referencia —la resolución de un acta— o del año de hoy. En un
 * acta, el tipo elegido en el formulario manda sobre el que trae el texto.
 */
export function readDocumentNumber(
    text: string,
    kind: Kind,
    issuedOn: string = '',
    actType: ActType | null = null,
    referenceYear: number | null = null,
    now: Date = new Date(),
): string | null {
    if (kind === 'other') {
        return null;
    }

    const partes = NUMERO.exec(text);

    if (!partes) {
        return null;
    }

    const fecha = /^(\d{4})-\d{2}/.exec(issuedOn);
    const referencia = fecha ? Number(fecha[1]) : referenceYear;
    const anio = partes[3]
        ? fullYear(Number(partes[3]), referencia, now)
        : null;
    const numero = `${Number(partes[2])}${anio === null ? '' : `/${anio}`}`;

    if (kind === 'resolution') {
        return `Resolución ${numero}`;
    }

    const escrito = partes[1]
        ? partes[1].replace(/\s+/g, '').toUpperCase() === 'LAB'
            ? 'LAB'
            : 'HYS'
        : null;
    const tipo = actType ?? escrito;

    return `Acta ${tipo === null ? '' : `${tipo} `}${numero}`;
}

/** El año de una fecha del formulario (`AAAA-MM-DD` o `AAAA-MM`), o `null`. */
export function yearOf(date: string | null | undefined): number | null {
    const partes = /^(\d{4})-\d{2}/.exec(date ?? '');

    return partes ? Number(partes[1]) : null;
}
