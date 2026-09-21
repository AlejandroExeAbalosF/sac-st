import { parseAmount } from './format';

/**
 * El importe en letras.
 *
 * Es la comprobación que de verdad atrapa un cero de más. Repetir el
 * número con puntos —«10.000.000,00»— obliga a contar grupos de tres, que
 * es exactamente el acto en el que se falla; «diez millones» y «cien
 * millones» no se parecen en nada y el error salta sin contar nada.
 *
 * Más adelante hace falta igual: el recibo lleva el importe en letras.
 */

const UNIDADES = [
    'cero',
    'uno',
    'dos',
    'tres',
    'cuatro',
    'cinco',
    'seis',
    'siete',
    'ocho',
    'nueve',
    'diez',
    'once',
    'doce',
    'trece',
    'catorce',
    'quince',
    'dieciséis',
    'diecisiete',
    'dieciocho',
    'diecinueve',
    'veinte',
    'veintiuno',
    'veintidós',
    'veintitrés',
    'veinticuatro',
    'veinticinco',
    'veintiséis',
    'veintisiete',
    'veintiocho',
    'veintinueve',
];

const DECENAS = [
    '',
    '',
    '',
    'treinta',
    'cuarenta',
    'cincuenta',
    'sesenta',
    'setenta',
    'ochenta',
    'noventa',
];

const CENTENAS = [
    '',
    'ciento',
    'doscientos',
    'trescientos',
    'cuatrocientos',
    'quinientos',
    'seiscientos',
    'setecientos',
    'ochocientos',
    'novecientos',
];

/** «uno» se apocopa delante de «mil» y de «millones»: veintiún mil. */
const apocopar = (texto: string): string => texto.replace(/uno$/, 'ún');

function hasta999(n: number): string {
    if (n === 0) {
        return '';
    }

    if (n === 100) {
        return 'cien';
    }

    const centena = Math.floor(n / 100);
    const resto = n % 100;
    const partes: string[] = [];

    if (centena > 0) {
        partes.push(CENTENAS[centena]);
    }

    if (resto > 0 && resto < 30) {
        partes.push(UNIDADES[resto]);
    } else if (resto >= 30) {
        const decena = Math.floor(resto / 10);
        const unidad = resto % 10;

        partes.push(
            unidad === 0
                ? DECENAS[decena]
                : `${DECENAS[decena]} y ${UNIDADES[unidad]}`,
        );
    }

    return partes.join(' ');
}

function hasta999999(n: number): string {
    const miles = Math.floor(n / 1000);
    const unidades = n % 1000;
    const partes: string[] = [];

    if (miles === 1) {
        // «mil», no «un mil».
        partes.push('mil');
    } else if (miles > 0) {
        partes.push(`${apocopar(hasta999(miles))} mil`);
    }

    if (unidades > 0) {
        partes.push(hasta999(unidades));
    }

    return partes.join(' ');
}

function enteroEnPalabras(n: number): string {
    if (n === 0) {
        return 'cero';
    }

    const millones = Math.floor(n / 1_000_000);
    const resto = n % 1_000_000;
    const partes: string[] = [];

    if (millones === 1) {
        partes.push('un millón');
    } else if (millones > 0) {
        partes.push(`${apocopar(hasta999999(millones))} millones`);
    }

    if (resto > 0) {
        partes.push(hasta999999(resto));
    }

    return partes.join(' ');
}

/**
 * `'1204500.50'` a `'un millón doscientos cuatro mil quinientos con
 * cincuenta centavos'`.
 *
 * Devuelve cadena vacía cuando no hay nada que decir o cuando el importe
 * excede lo que se puede leer en voz alta sin perder el hilo. Es una ayuda
 * de lectura: por encima de eso no ayuda, y no vale la pena arrastrar el
 * caso raro por toda la escala.
 */
export function amountInWords(value: string | null | undefined): string {
    if (!value) {
        return '';
    }

    const normalizado = parseAmount(value);

    if (normalizado === '') {
        return '';
    }

    const negativo = normalizado.startsWith('-');
    const [entero, centavos] = normalizado.replace('-', '').split('.');

    // Doce dígitos son novecientos noventa y nueve mil millones: más allá
    // el número deja de ser legible y el eco no aporta.
    if (entero.length > 12) {
        return '';
    }

    const pesos = enteroEnPalabras(Number(entero));
    const partes = [negativo ? `menos ${pesos}` : pesos];

    if (centavos !== '00') {
        partes.push(`con ${enteroEnPalabras(Number(centavos))} centavos`);
    }

    return partes.join(' ');
}
