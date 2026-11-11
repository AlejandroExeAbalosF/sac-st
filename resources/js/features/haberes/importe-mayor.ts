import { compareAmounts, parseAmount, subtractAmounts } from '@/lib/format';
import type { DecimalString } from '@/lib/format';

/**
 * El importe que se cobra o se deposita según la sección de importe mayor:
 * «Entró más de lo que se debe» en el cobro por mostrador, «El depósito es
 * por más de lo que se debe» en la carga del comprobante.
 *
 * Plegada, vale lo de siempre: lo pendiente en el cobro, lo que la cuota
 * espera en el comprobante. Abierta, el total escrito, pero solo si supera lo
 * que se debe: un total vacío o menor no puede caer en silencio en el importe
 * de siempre, así que queda **incompleto** y la pantalla no envía. Para usar
 * el importe de la cuota, se pliega la sección.
 *
 * El servidor decide lo mismo por su cuenta; esto decide qué se muestra y qué
 * se envía.
 */
export function importeMayor(
    abierto: boolean,
    escrito: string,
    aAbonar: DecimalString,
    pendiente: DecimalString,
): {
    /** El total, si la sección está abierta y supera lo que se debe. */
    total: DecimalString | null;
    excedente: DecimalString | null;
    importe: DecimalString;
    incompleto: boolean;
} {
    const total = abierto ? parseAmount(escrito) : '';
    const diferencia = total === '' ? null : subtractAmounts(total, aAbonar);
    const mayor = diferencia !== null && compareAmounts(diferencia, '0') === 1;

    return {
        total: mayor ? total : null,
        excedente: mayor ? diferencia : null,
        importe: mayor ? total : pendiente,
        incompleto: abierto && !mayor,
    };
}
