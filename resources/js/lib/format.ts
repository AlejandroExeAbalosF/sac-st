/**
 * Formato de presentación — el único lugar del sistema donde se decide
 * cómo se ve un peso, un CUIT, un CBU o una fecha.
 *
 * Regla del modelo de datos: ningún monto se convierte a `number` en
 * ninguna parte de la pila. Los importes llegan del backend como string
 * decimal (`numeric(19,2)` → cast `decimal:2` de Eloquent) y se formatean
 * operando sobre esa cadena. Convertirlos a `number` reintroduciría el
 * punto flotante que el modelo prohíbe explícitamente.
 */

export const DISPLAY_TIME_ZONE = 'America/Argentina/Salta';

export type CurrencyCode = 'ARS' | 'USD';

/** Un importe tal como viaja desde PHP: "1204500.00", "-3890.06". */
export type DecimalString = string;

type MoneyOptions = {
    /** Antepone el símbolo de la moneda. Por defecto, sí. */
    symbol?: boolean;
    /** ARS por defecto hasta que una pantalla ofrezca otra moneda. */
    currency?: CurrencyCode;
    /** Muestra `+` en los positivos. Por defecto, no. */
    explicitSign?: boolean;
};

/**
 * Formatea un importe decimal al estilo argentino: `$ 1.204.500,00`.
 * Los negativos usan el signo menos tipográfico (−), no el guion.
 */
export function money(
    value: DecimalString | null | undefined,
    options: MoneyOptions = {},
): string {
    const { symbol = true, currency = 'ARS', explicitSign = false } = options;

    if (value === null || value === undefined || value === '') {
        return symbol ? `${currencySymbol(currency)} —` : '—';
    }

    const recortado = value.trimStart();
    const negative = recortado.startsWith('-') || recortado.startsWith('−');
    const digits = value.replace(/[^0-9.]/g, '');
    const [rawInteger = '0', rawFraction = ''] = digits.split('.');

    const integer = groupThousands(rawInteger.replace(/^0+(?=\d)/, ''));
    const fraction = rawFraction.padEnd(2, '0').slice(0, 2);

    const sign = negative ? '−' : explicitSign ? '+' : '';
    const prefix = symbol ? `${currencySymbol(currency)} ` : '';

    return `${sign}${prefix}${integer},${fraction}`;
}

function currencySymbol(currency: CurrencyCode): string {
    return currency === 'USD' ? 'US$' : '$';
}

/**
 * Fecha de negocio en Salta, sin el desfasaje UTC que adelanta el día desde
 * las 21:00 locales. Se arma desde partes para no depender del formato que
 * un navegador elija para `toLocaleDateString`.
 */
export function businessToday(now: Date = new Date()): string {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: DISPLAY_TIME_ZONE,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(now);

    const part = (type: Intl.DateTimeFormatPartTypes): string =>
        parts.find((item) => item.type === type)?.value ?? '';

    return `${part('year')}-${part('month')}-${part('day')}`;
}

/** Agrupa de a tres con punto, sin pasar por `number`. */
function groupThousands(integer: string): string {
    return integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

/**
 * Normaliza lo que tipeó el usuario a un decimal que PHP entiende.
 *
 * Acepta `1.204.500,00`, `1204500,00` y `1204500.00`. La coma es el
 * separador decimal en Argentina y el punto agrupa los miles, así que el
 * último separador manda: si hay coma, el punto es de miles.
 */
export function parseAmount(input: string): DecimalString {
    const limpio = input.trim().replace(/\s/g, '');

    if (limpio === '') {
        return '';
    }

    // El menos tipografico es el que escribe `money()`: sin esto, un
    // importe formateado no se puede volver a leer.
    const negativo = limpio.startsWith('-') || limpio.startsWith('−');
    const cuerpo = negativo ? limpio.slice(1) : limpio;

    let entero: string;
    let decimal: string;

    if (cuerpo.includes(',')) {
        // Formato local: los puntos separan miles, la coma los decimales.
        const [izquierda, derecha = ''] = cuerpo.split(',');
        entero = izquierda.replace(/\./g, '');
        decimal = derecha;
    } else {
        /*
         * Sin coma hay que decidir qué es cada punto. Uno seguido de
         * exactamente tres dígitos y nada más que sea dígito es separador
         * de miles; el que sobrevive a esa criba —si sobrevive— es el
         * decimal.
         *
         * Acá estaba el error: antes se quitaban los separadores de miles
         * pero la parte entera se tomaba de la cadena completa, así que el
         * punto decimal se perdía junto con sus cifras y estas quedaban
         * pegadas a los pesos. `1000.50` se leía como cien mil cincuenta,
         * y `parseAmount` dejaba de ser idempotente: normalizar dos veces
         * multiplicaba el importe por cien.
         */
        const sinMiles = cuerpo.replace(/\.(?=\d{3}(?:\D|$))/g, '');
        const corte = sinMiles.lastIndexOf('.');

        entero = corte === -1 ? sinMiles : sinMiles.slice(0, corte);
        decimal = corte === -1 ? '' : sinMiles.slice(corte + 1);
    }

    const soloDigitos = (s: string) => s.replace(/\D/g, '');

    return `${negativo ? '-' : ''}${soloDigitos(entero) || '0'}.${soloDigitos(decimal).padEnd(2, '0').slice(0, 2)}`;
}

/** Centavos exactos. `BigInt` evita el punto flotante por completo. */
function toCents(value: DecimalString): bigint {
    const normalizado = parseAmount(value);

    if (normalizado === '') {
        return 0n;
    }

    return BigInt(normalizado.replace('.', ''));
}

function fromCents(cents: bigint): DecimalString {
    const negativo = cents < 0n;
    const abs = negativo ? -cents : cents;
    const texto = abs.toString().padStart(3, '0');

    return `${negativo ? '-' : ''}${texto.slice(0, -2)}.${texto.slice(-2)}`;
}

/**
 * Suma importes decimales sin pasar por `number`.
 *
 * Es la contraparte en el navegador de lo que `bcmath` hace en PHP: sumar
 * `0.1 + 0.2` con punto flotante da `0.30000000000000004`, y en una
 * pantalla que compara la suma de las cuotas contra el importe del haber
 * eso significa rechazar cargas correctas por un centavo fantasma.
 */
export function sumAmounts(
    values: Array<DecimalString | null | undefined>,
): DecimalString {
    return fromCents(
        values.reduce<bigint>(
            (total, value) => total + (value ? toCents(value) : 0n),
            0n,
        ),
    );
}

/**
 * `a - b`, con la misma exactitud que `sumAmounts`.
 *
 * Existe porque restar es lo que hace una diferencia de arqueo, y
 * escribirla como `Number(a) - Number(b)` en la pantalla que la muestra
 * sería meter el punto flotante justo donde importa.
 */
export function subtractAmounts(
    a: DecimalString | null | undefined,
    b: DecimalString | null | undefined,
): DecimalString {
    return fromCents((a ? toCents(a) : 0n) - (b ? toCents(b) : 0n));
}

/** −1, 0 o 1. Comparación exacta, sin punto flotante. */
export function compareAmounts(a: DecimalString, b: DecimalString): -1 | 0 | 1 {
    const ca = toCents(a);
    const cb = toCents(b);

    return ca === cb ? 0 : ca < cb ? -1 : 1;
}

/** `true` si el importe decimal es distinto de cero. */
export function isNonZero(value: DecimalString | null | undefined): boolean {
    if (!value) {
        return false;
    }

    return /[1-9]/.test(value);
}

/** `true` si el importe decimal es negativo. */
export function isNegative(value: DecimalString | null | undefined): boolean {
    return (
        Boolean(value) && value!.trimStart().startsWith('-') && isNonZero(value)
    );
}

/** `20-12345678-9` a partir de 11 dígitos. Devuelve el original si no lo son. */
export function cuit(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const digits = value.replace(/\D/g, '');

    if (digits.length !== 11) {
        return value;
    }

    return `${digits.slice(0, 2)}-${digits.slice(2, 10)}-${digits.slice(10)}`;
}

/** `29.939.415` a partir de 6 a 8 dígitos. Devuelve el original si no lo son. */
export function dni(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const digits = value.replace(/\D/g, '');

    if (digits.length < 6 || digits.length > 8) {
        return value;
    }

    return groupThousands(digits);
}

/**
 * La máscara del documento mientras se tipea.
 *
 * Decide por la cantidad de dígitos y nada más: hasta ocho es un DNI, once
 * es un CUIT o un CUIL. Los estados intermedios quedan sin separadores, que
 * es la forma de decir «esto todavía no es ninguno de los dos».
 *
 * **No valida ni interpreta.** De quién es el número lo decide el servidor,
 * que es el único que conoce el dígito verificador y los prefijos; esto
 * solo agrupa lo que ya está escrito para que se pueda cotejar contra el
 * papel de un vistazo.
 */
export function documentMask(value: string | null | undefined): string {
    const digits = (value ?? '').replace(/\D/g, '');

    if (digits.length >= 6 && digits.length <= 8) {
        return groupThousands(digits);
    }

    if (digits.length === 11) {
        return cuit(digits);
    }

    return digits;
}

/** CBU en bloques de 4 para poder cotejarlo a simple vista. */
export function cbu(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const digits = value.replace(/\D/g, '');

    if (digits.length !== 22) {
        return value;
    }

    return digits.replace(/(.{4})/g, '$1 ').trim();
}

/** Número de comprobante: serie de 4 + secuencia de 8 → `0010/00071514`. */
export function documentNumber(
    series: string,
    sequence: number | string,
): string {
    return `${series.padStart(4, '0')}/${String(sequence).padStart(8, '0')}`;
}

/** Una fecha de calendario sin hora, tal como la manda PHP: "2026-08-07". */
const SOLO_FECHA = /^\d{4}-\d{2}-\d{2}$/;

/**
 * Fecha operativa: `07/08/2026`.
 *
 * Una fecha de calendario NO se convierte de zona horaria. `date` en
 * PostgreSQL y `toDateString()` en PHP describen un día del almanaque, no
 * un instante: el 7 de agosto es el 7 de agosto en cualquier huso.
 *
 * Pasarla por `new Date('2026-08-07')` la interpreta como medianoche UTC y,
 * al presentarla en Salta (UTC−3), retrocede al día anterior. En un
 * sistema contable eso es grave: la fecha operativa de un cierre de caja
 * determina a qué período pertenece cada movimiento.
 */
export function date(value: string | Date | null | undefined): string {
    if (!value) {
        return '—';
    }

    if (typeof value === 'string' && SOLO_FECHA.test(value)) {
        const [anio, mes, dia] = value.split('-');

        return `${dia}/${mes}/${anio}`;
    }

    return new Intl.DateTimeFormat('es-AR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        timeZone: DISPLAY_TIME_ZONE,
    }).format(typeof value === 'string' ? new Date(value) : value);
}

/**
 * Fecha y hora de registración: `07/08/2026 14:32`.
 *
 * Acá sí corresponde convertir: es un instante. El backend lo guarda en
 * UTC (`timestamptz`) y se presenta en hora de Salta.
 *
 * Se arma con `formatToParts` en lugar de `format` porque el formato
 * es-AR intercala una coma entre la fecha y la hora, y en una columna de
 * tabla esa coma rompe la alineación.
 */
export function dateTime(value: string | Date | null | undefined): string {
    if (!value) {
        return '—';
    }

    const partes = new Intl.DateTimeFormat('es-AR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
        timeZone: DISPLAY_TIME_ZONE,
    }).formatToParts(typeof value === 'string' ? new Date(value) : value);

    const parte = (tipo: Intl.DateTimeFormatPartTypes): string =>
        partes.find((p) => p.type === tipo)?.value ?? '';

    return `${parte('day')}/${parte('month')}/${parte('year')} ${parte('hour')}:${parte('minute')}`;
}
