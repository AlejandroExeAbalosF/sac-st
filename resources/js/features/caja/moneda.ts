import type { CurrencyCode } from '@/lib/format';

/**
 * La moneda viaja en la URL entre las pantallas de la Caja.
 *
 * El mismo cajón guarda pesos y dólares y la base los lleva separados, así
 * que cuál de los dos libros se está mirando es un dato de la vista. Si un
 * enlace lo perdiera, pasar de la Caja al Calendario cambiaría la moneda
 * sin decirlo — y en una pantalla donde el importe es el dato, mostrar
 * dólares con cara de pesos es el peor error posible.
 *
 * Los pesos no se anotan: son la moneda de la casa y `/caja/dia` a secas
 * sigue siendo la dirección canónica.
 */
export function conMoneda(url: string, moneda: CurrencyCode): string {
    if (moneda === 'ARS') {
        return url;
    }

    return `${url}${url.includes('?') ? '&' : '?'}moneda=${moneda.toLowerCase()}`;
}
