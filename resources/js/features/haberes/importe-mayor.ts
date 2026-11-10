import { compareAmounts, parseAmount, subtractAmounts } from '@/lib/format';
import type { DecimalString } from '@/lib/format';

/**
 * Lo que cobra el diálogo de cobro según la sección «Entró más de lo que se debe».
 *
 * Plegada, se cobra lo pendiente de la cuota. Abierta, el total escrito, pero
 * solo si supera lo que se debe: un total vacío o menor no puede caer en
 * silencio en el cobro de siempre, así que queda **incompleto** y el diálogo no
 * envía. Para cobrar lo que se debe, se pliega la sección.
 *
 * El servidor decide lo mismo por su cuenta; esto decide qué se muestra y qué
 * se envía.
 */
export function importeMayorDelCobro(
    abierto: boolean,
    escrito: string,
    aAbonar: DecimalString,
    pendiente: DecimalString,
): {
    /** El total, si la sección está abierta y supera lo que se debe. */
    total: DecimalString | null;
    excedente: DecimalString | null;
    aCobrar: DecimalString;
    incompleto: boolean;
} {
    const total = abierto ? parseAmount(escrito) : '';
    const diferencia = total === '' ? null : subtractAmounts(total, aAbonar);
    const mayor = diferencia !== null && compareAmounts(diferencia, '0') === 1;

    return {
        total: mayor ? total : null,
        excedente: mayor ? diferencia : null,
        aCobrar: mayor ? total : pendiente,
        incompleto: abierto && !mayor,
    };
}
