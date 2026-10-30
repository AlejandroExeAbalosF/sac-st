import { usePage } from '@inertiajs/react';
import { calendario, dia } from '@/routes/caja';
import { index as aperturaDeHaberes } from '@/routes/caja/apertura';
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
import {
    defaultMethod as predeterminar,
    index as cuentas,
    retire as darDeBaja,
    start as moverInicio,
    store as habilitar,
} from '@/routes/caja/cuentas';
import { index as saldoAnterior } from '@/routes/caja/saldo-anterior';
import {
    calendario as calendarioDeMultas,
    dia as diaDeMultas,
} from '@/routes/multas/caja';
import { index as aperturaDeMultas } from '@/routes/multas/caja/apertura';
import {
    adjust as imputarEnMultas,
    index as arqueosDeMultas,
    review as revisarEnMultas,
    store as arquearEnMultas,
} from '@/routes/multas/caja/arqueos';
import {
    index as cierresDeMultas,
    reopen as reabrirEnMultas,
    sheet as planillaDeMultas,
    store as cerrarEnMultas,
} from '@/routes/multas/caja/cierres';
import { regenerate as rehacerEnMultas } from '@/routes/multas/caja/cierres/sheet';
import {
    defaultMethod as predeterminarEnMultas,
    index as cuentasDeMultas,
    retire as darDeBajaEnMultas,
    start as moverInicioEnMultas,
    store as habilitarEnMultas,
} from '@/routes/multas/caja/cuentas';
import type { RouteQueryOptions } from '@/wayfinder';

/** Las cajas que tienen pantallas de caja. */
export type CodigoDeCaja = 'haberes' | 'multas';

type Ruta = { url: string };
type ConQuery = (options?: RouteQueryOptions) => Ruta;
type ConArqueo = (args: { cashCount: number }) => Ruta;
type ConCierre = (args: { closing: number }) => Ruta;
type ConCuenta = (args: { link: number }) => Ruta;

export type RutasDeCaja = {
    /** El rótulo de las pantallas: de qué caja son. */
    rotulo: string;
    dia: ConQuery;
    calendario: ConQuery;
    arqueos: {
        index: ConQuery;
        store: () => Ruta;
        review: ConArqueo;
        adjust: ConArqueo;
    };
    cierres: {
        index: ConQuery;
        store: () => Ruta;
        reopen: ConCierre;
        sheet: ConCierre;
        regenerate: ConCierre;
    };
    /** Dónde se abre el libro, y se ve lo que se declaró al abrirlo. */
    apertura: () => Ruta;
    /** El saldo del sistema anterior: solo Haberes reserva por cuota. */
    saldoAnterior: (() => Ruta) | null;
    /** Las cuentas bancarias con las que opera la caja. */
    cuentas: {
        index: () => Ruta;
        store: () => Ruta;
        retire: ConCuenta;
        default: ConCuenta;
        start: ConCuenta;
    };
};

const RUTAS: Record<CodigoDeCaja, RutasDeCaja> = {
    haberes: {
        rotulo: 'Caja',
        dia,
        calendario,
        arqueos: { index: arqueos, store: arquear, review, adjust },
        cierres: { index: cierres, store: cerrar, reopen, sheet, regenerate },
        apertura: aperturaDeHaberes,
        saldoAnterior,
        cuentas: {
            index: cuentas,
            store: habilitar,
            retire: darDeBaja,
            default: predeterminar,
            start: moverInicio,
        },
    },
    multas: {
        rotulo: 'Caja de Multas',
        dia: diaDeMultas,
        calendario: calendarioDeMultas,
        arqueos: {
            index: arqueosDeMultas,
            store: arquearEnMultas,
            review: revisarEnMultas,
            adjust: imputarEnMultas,
        },
        cierres: {
            index: cierresDeMultas,
            store: cerrarEnMultas,
            reopen: reabrirEnMultas,
            sheet: planillaDeMultas,
            regenerate: rehacerEnMultas,
        },
        apertura: aperturaDeMultas,
        saldoAnterior: null,
        cuentas: {
            index: cuentasDeMultas,
            store: habilitarEnMultas,
            retire: darDeBajaEnMultas,
            default: predeterminarEnMultas,
            start: moverInicioEnMultas,
        },
    },
};

/** Las de esa caja, o nada si la caja no tiene pantallas de caja. */
export function rutasDeCajaSiExiste(
    codigo: string | null | undefined,
): RutasDeCaja | null {
    return codigo !== null && codigo !== undefined && codigo in RUTAS
        ? RUTAS[codigo as CodigoDeCaja]
        : null;
}

/**
 * Las direcciones de las pantallas de caja de una caja.
 *
 * Las pantallas son las mismas para todas las cajas, pero cada una vive en
 * su familia de rutas, con su caja y sus permisos. Una caja sin rutas es un
 * error de programación, no un motivo para mandar a Haberes.
 */
export function rutasDeCaja(codigo: string): RutasDeCaja {
    const rutas = rutasDeCajaSiExiste(codigo);

    if (rutas === null) {
        throw new Error(`La caja «${codigo}» no tiene pantallas de caja.`);
    }

    return rutas;
}

/** Las de la caja que está mirando la pantalla (`selected.cashBoxCode`). */
export function useRutasDeCaja(): RutasDeCaja {
    const { selected } = usePage<{ selected: { cashBoxCode: string } }>().props;

    return rutasDeCaja(selected.cashBoxCode);
}
