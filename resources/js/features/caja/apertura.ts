import type { CurrencyCode } from '@/lib/format';
import { index as apertura } from '@/routes/caja/apertura';
import { conMoneda } from './moneda';

const NOMBRES: Record<CurrencyCode, string> = {
    ARS: 'pesos',
    USD: 'dólares',
};

/** «pesos», «dólares»: para armar frases, no para encabezar. */
export function nombreDeMoneda(moneda: CurrencyCode): string {
    return NOMBRES[moneda];
}

export type AperturaPendiente = {
    titulo: string;
    detalle: string;
    /** A dónde ir a abrir; nulo si quien mira no puede hacerlo. */
    href: string | null;
    accion: string;
};

/**
 * Si el día mirado es anterior a la apertura del libro.
 *
 * Antes de esa fecha el libro no tiene nada —el saldo de ese día es el que
 * la apertura declaró— y la base rechaza cualquier movimiento, arqueo o
 * cierre. Las fechas son `AAAA-MM-DD`, que se comparan bien como texto.
 */
export function esAnteriorALaApertura(
    fecha: string,
    apertura: { date: string } | null,
): boolean {
    return apertura !== null && fecha < apertura.date;
}

/**
 * Qué decir cuando un libro todavía no se abrió, y qué salida ofrecer.
 *
 * Lo comparten el panel de las pantallas de Caja y el diálogo que aparece
 * cuando una operación choca con un libro sin apertura: la explicación es
 * la misma y no debería poder decir cosas distintas en dos lugares.
 *
 * Abrir es del administrador. A quien no puede, no se le ofrece un enlace
 * que terminaría en un 403: se le dice a quién pedírselo.
 */
export function aperturaPendiente(
    moneda: CurrencyCode,
    puedeAbrir: boolean,
): AperturaPendiente {
    const nombre = nombreDeMoneda(moneda);

    return {
        titulo: `Los libros en ${nombre} de esta caja todavía no se abrieron`,
        detalle:
            `Antes de registrar cobros, pagos, arqueos o cierres en ${nombre} hay que ` +
            'declarar lo que ya estaba en el cajón, los cheques y la cuenta. Si no había ' +
            'nada, se abre igual, declarándolo.',
        href: puedeAbrir ? conMoneda(apertura().url, moneda) : null,
        accion: puedeAbrir
            ? `Abrir libros en ${nombre}`
            : 'Pedile a un administrador que abra los libros.',
    };
}
