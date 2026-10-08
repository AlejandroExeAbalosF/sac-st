import { compareAmounts } from '@/lib/format';

type CuotaStatus = App.Modules.Haberes.Enums.InstallmentWorkflowStatus;

/** Los dos caminos de una cuota del sistema anterior. */
export type CaminoHistorico = 'pagada' | 'pendiente';

/** Si se puede tomar un camino y, si no, por qué. */
export type OpcionHistorica = { disponible: boolean; motivo: string | null };

export type EntradaHistorica = {
    estado: CuotaStatus;
    /** Si la cuota ya tiene plata asignada. */
    financiada: boolean;
    /** Si ya tiene un recibo de ingreso emitido por el sistema. */
    conReciboDelSistema: boolean;
    /**
     * Por qué el servidor no la deja dar por pagada; `null` si puede y
     * `undefined` si no mandó nada para esta cuota.
     */
    obstaculo: string | null | undefined;
    puedeRegistrar: boolean;
    puedeReservar: boolean;
    /** Lo que queda del sistema anterior sin reservar; `null` si no se sabe. */
    saldoAnterior: string | null;
    /** La apertura de la caja de Haberes en la moneda del haber; `null` sin apertura. */
    apertura: string | null;
};

const disponible: OpcionHistorica = { disponible: true, motivo: null };
const no = (motivo: string): OpcionHistorica => ({ disponible: false, motivo });

/**
 * Qué se puede hacer con una cuota del sistema anterior, y por qué no.
 *
 * Es la respuesta del formulario guiado: la pregunta —¿el beneficiario ya
 * cobró?— tiene dos respuestas, y una puede no estar disponible. En vez de
 * esconderla, se muestra apagada con el motivo, para que el operador sepa
 * qué falta y no crea que el sistema no contempla el caso.
 *
 * Las reglas son las del servidor, que es quien decide: esto solo anticipa
 * lo que el Action rechazaría, para no ofrecer un camino sin salida.
 */
export function opcionesHistoricas(
    entrada: EntradaHistorica,
): Record<CaminoHistorico, OpcionHistorica> {
    if (entrada.estado !== 'active') {
        const motivo = 'La cuota no está pendiente.';

        return { pagada: no(motivo), pendiente: no(motivo) };
    }

    if (entrada.apertura === null) {
        const motivo =
            'La caja de Haberes todavía no tiene apertura en la moneda de este haber: sin ella no se puede saber qué es del sistema anterior.';

        return { pagada: no(motivo), pendiente: no(motivo) };
    }

    return { pagada: pagada(entrada), pendiente: pendiente(entrada) };
}

/**
 * Si el formulario guiado tiene algo para ofrecer en esta cuota.
 *
 * Una cuota que ya entró por el circuito actual no es del sistema anterior,
 * y quien no tiene ninguno de los dos permisos no puede hacer nada: en esos
 * casos el botón no aparece. En el resto aparece aunque los dos caminos
 * estén cerrados, porque el diálogo explica por qué.
 */
export function ofreceGuiaHistorica(entrada: EntradaHistorica): boolean {
    return (
        entrada.estado === 'active' &&
        (entrada.puedeRegistrar || entrada.puedeReservar) &&
        !entrada.financiada &&
        !entrada.conReciboDelSistema
    );
}

function pagada(entrada: EntradaHistorica): OpcionHistorica {
    if (!entrada.puedeRegistrar) {
        return no(
            'Tu usuario no tiene permiso para registrar cuotas históricas.',
        );
    }

    if (entrada.obstaculo === undefined) {
        return no('No llegaron los datos del sistema anterior de esta cuota.');
    }

    return entrada.obstaculo === null ? disponible : no(entrada.obstaculo);
}

function pendiente(entrada: EntradaHistorica): OpcionHistorica {
    if (!entrada.puedeReservar) {
        return no(
            'Tu usuario no tiene permiso para reservar fondos del sistema anterior.',
        );
    }

    if (entrada.financiada || entrada.conReciboDelSistema) {
        return no(
            'La cuota ya tiene plata o un recibo del circuito actual: no se mezcla con plata del sistema anterior.',
        );
    }

    if (
        entrada.saldoAnterior === null ||
        compareAmounts(entrada.saldoAnterior, '0.00') !== 1
    ) {
        return no('No queda saldo del sistema anterior sin reservar.');
    }

    return disponible;
}
