import { parseAmount } from '@/lib/format';
import type { DecimalString } from '@/lib/format';

/**
 * Las reglas de dato del campo de importe, separadas del componente para
 * poder probarlas sin un navegador.
 *
 * El campo **muestra** `2.034.800,00` y **guarda** `2034800.00`: un decimal
 * en texto, con punto, que es lo que PHP espera y lo que `parseAmount`,
 * `sumAmounts` y el resto de `lib/format` ya entienden. En ningún paso el
 * importe se convierte a `number`.
 */

/** Un decimal a medio escribir: `2034800`, `2034800.`, `2034800.5`. */
const NUMERICO = /^-?\d*(\.\d{0,2})?$/;

/**
 * Lo que recibe el campo, en la forma que entiende `react-number-format`.
 *
 * Una pantalla puede pasarle el decimal del servidor (`2034800.00`), lo que
 * el mismo campo emitió mientras se escribía (`2034800.5`) o un importe ya
 * formateado que armó con `money()` (`2.034.800,00`). Los dos primeros pasan
 * tal cual —normalizarlos en cada tecla rellenaría los centavos mientras se
 * escriben—; el tercero se lee con `parseAmount`.
 */
export function amountToNumeric(value: string | null | undefined): string {
    if (value === null || value === undefined || value.trim() === '') {
        return '';
    }

    return NUMERICO.test(value) ? value : parseAmount(value);
}

/**
 * Al salir del campo: el decimal completo, con sus dos centavos.
 *
 * Vacío sigue vacío —borrar el importe es una respuesta válida— y un signo
 * suelto también se descarta.
 */
export function amountOnBlur(value: string): DecimalString {
    const numerico = amountToNumeric(value);

    if (numerico === '' || numerico === '-') {
        return '';
    }

    return parseAmount(numerico);
}

/**
 * Lo que se pegó, leído como importe.
 *
 * Se pega de todo: `2034800.00` desde un sistema, `2.034.800,00` desde una
 * planilla, `$ 2.034.800,00` desde un PDF. `react-number-format` tomaría el
 * punto como separador de miles y convertiría `2034800.00` en doscientos
 * millones; `parseAmount` decide por el último separador, como el
 * servidor. Lo que no tiene ni un dígito no es un importe y se ignora.
 */
export function amountFromPaste(text: string): DecimalString | null {
    const limpio = text.replace(/US\$|[$\s]/g, '');

    if (!/\d/.test(limpio)) {
        return null;
    }

    return parseAmount(limpio);
}
