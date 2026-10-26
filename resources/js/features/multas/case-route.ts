import { show } from '@/routes/multas/expedientes';
import { create } from '@/routes/multas/expedientes/multas';
import { numerada } from '@/routes/multas/expedientes/multas/create';

/**
 * La ficha del expediente: `/multas/expedientes/75/169607-2024-0`.
 *
 * El id identifica; el número solo describe, y lo manda el servidor
 * (`routeNumber`) para que el enlace llegue directo a la dirección actual.
 * Un expediente importado sin número normalizado va solo con el id.
 */
export function caseRoute(id: number, routeNumber: string | null) {
    return routeNumber === null
        ? show({ fineCase: id })
        : show({ fineCase: id, numero: routeNumber });
}

/** «Agregar multa» desde la ficha, con la misma forma de dirección. */
export function addFineRoute(id: number, routeNumber: string | null) {
    return routeNumber === null
        ? create(id)
        : numerada({ fineCase: id, numero: routeNumber });
}
