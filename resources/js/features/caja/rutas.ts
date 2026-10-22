import { usePage } from '@inertiajs/react';
import { calendario, dia } from '@/routes/caja';
import {
    adjust,
    index as arqueos,
    review,
    store as arquear,
} from '@/routes/caja/arqueos';
import {
    index as cierres,
    reopen,
    sheet,
    store as cerrar,
} from '@/routes/caja/cierres';
import { regenerate } from '@/routes/caja/cierres/sheet';

/** Las cajas que tienen pantallas de caja. */
export type CodigoDeCaja = 'haberes';

const HABERES = {
    dia,
    calendario,
    arqueos: { index: arqueos, store: arquear, review, adjust },
    cierres: { index: cierres, store: cerrar, reopen, sheet, regenerate },
};

export type RutasDeCaja = typeof HABERES;

const RUTAS: Record<CodigoDeCaja, RutasDeCaja> = {
    haberes: HABERES,
};

/**
 * Las direcciones de las pantallas de caja de una caja.
 *
 * Las pantallas son las mismas para todas las cajas, pero cada una vive en
 * su familia de rutas, con su caja y sus permisos. Una caja sin rutas es un
 * error de programación, no un motivo para mandar a Haberes.
 */
export function rutasDeCaja(codigo: string): RutasDeCaja {
    const rutas = RUTAS[codigo as CodigoDeCaja];

    if (rutas === undefined) {
        throw new Error(`La caja «${codigo}» no tiene pantallas de caja.`);
    }

    return rutas;
}

/** Las de la caja que está mirando la pantalla (`selected.cashBoxCode`). */
export function useRutasDeCaja(): RutasDeCaja {
    const { selected } = usePage<{ selected: { cashBoxCode: string } }>().props;

    return rutasDeCaja(selected.cashBoxCode);
}
