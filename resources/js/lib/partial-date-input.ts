/**
 * Una fecha de la que puede saberse solo el mes: `AAAA-MM-DD` o `AAAA-MM`.
 *
 * Es lo que acepta el servidor (`PartialDateRule`). En pantalla, el mes se
 * escribe `mm/aaaa`: estas funciones van y vuelven entre las dos formas.
 */

/** `2026-05` → `052026`: las cifras que muestra el campo del mes. */
export function monthToDigits(value: string): string {
    const partes = /^(\d{4})-(\d{2})$/.exec(value);

    return partes ? `${partes[2]}${partes[1]}` : '';
}

/**
 * `052026` → `2026-05`. Vacío mientras faltan cifras o si el mes no existe:
 * el formulario no recibe un mes a medias.
 */
export function digitsToMonth(digits: string): string {
    const partes = /^(\d{2})(\d{4})$/.exec(digits);

    if (!partes || Number(partes[1]) < 1 || Number(partes[1]) > 12) {
        return '';
    }

    return `${partes[2]}-${partes[1]}`;
}

/**
 * Un mes pegado: `05/2026`, `5/2026`, `05-2026` o ya en `2026-05`. `null`
 * si no se lee como un mes.
 */
export function parsePastedMonth(text: string): string | null {
    const limpio = text.trim();
    const local = /^(\d{1,2})[-/.](\d{4})$/.exec(limpio);
    const iso = /^(\d{4})-(\d{1,2})$/.exec(limpio);
    const [anio, mes] = local
        ? [local[2], local[1]]
        : iso
          ? [iso[1], iso[2]]
          : [null, null];

    if (anio === null || mes === null || Number(mes) < 1 || Number(mes) > 12) {
        return null;
    }

    return `${anio}-${mes.padStart(2, '0')}`;
}

/** La fecha guardada es solo un mes. */
export function isMonthOnly(value: string): boolean {
    return /^\d{4}-\d{2}$/.test(value);
}
