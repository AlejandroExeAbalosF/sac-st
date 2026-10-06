import { describe, expect, it } from 'vitest';
import type { EntradaHistorica } from './legacy-options';
import { ofreceGuiaHistorica, opcionesHistoricas } from './legacy-options';

/** Una cuota recién cargada, con los dos permisos y saldo anterior. */
const base: EntradaHistorica = {
    estado: 'active',
    financiada: false,
    conReciboDelSistema: false,
    obstaculo: null,
    puedeRegistrar: true,
    puedeReservar: true,
    saldoAnterior: '5354561.71',
    apertura: '2026-06-01',
};

describe('opcionesHistoricas', () => {
    it('ofrece los dos caminos en una cuota recién cargada', () => {
        expect(opcionesHistoricas(base)).toEqual({
            pagada: { disponible: true, motivo: null },
            pendiente: { disponible: true, motivo: null },
        });
    });

    it('sin apertura cierra los dos caminos y dice por qué', () => {
        const opciones = opcionesHistoricas({ ...base, apertura: null });

        expect(opciones.pagada.disponible).toBe(false);
        expect(opciones.pendiente.disponible).toBe(false);
        expect(opciones.pagada.motivo).toContain('no tiene apertura');
    });

    it('sin permiso para reservar, explica que es un tema de permisos', () => {
        const opciones = opcionesHistoricas({ ...base, puedeReservar: false });

        expect(opciones.pagada.disponible).toBe(true);
        expect(opciones.pendiente.motivo).toContain('no tiene permiso');
    });

    it('sin saldo anterior no se puede reservar', () => {
        const opciones = opcionesHistoricas({
            ...base,
            saldoAnterior: '0.00',
        });

        expect(opciones.pendiente.motivo).toBe(
            'No queda saldo del sistema anterior sin reservar.',
        );
    });

    it('usa el motivo del servidor para no dar por pagada', () => {
        const opciones = opcionesHistoricas({
            ...base,
            obstaculo: 'La cuota tiene un recibo de papel vigente.',
        });

        expect(opciones.pagada).toEqual({
            disponible: false,
            motivo: 'La cuota tiene un recibo de papel vigente.',
        });
        expect(opciones.pendiente.disponible).toBe(true);
    });
});

describe('ofreceGuiaHistorica', () => {
    it('aparece en una cuota pendiente sin movimientos del circuito', () => {
        expect(ofreceGuiaHistorica(base)).toBe(true);
    });

    it('aparece aunque los dos caminos estén cerrados, para explicarlo', () => {
        expect(ofreceGuiaHistorica({ ...base, apertura: null })).toBe(true);
    });

    it('no aparece en una cuota que ya entró por el circuito actual', () => {
        expect(ofreceGuiaHistorica({ ...base, financiada: true })).toBe(false);
        expect(
            ofreceGuiaHistorica({ ...base, conReciboDelSistema: true }),
        ).toBe(false);
    });

    it('no aparece para quien no tiene ninguno de los dos permisos', () => {
        expect(
            ofreceGuiaHistorica({
                ...base,
                puedeRegistrar: false,
                puedeReservar: false,
            }),
        ).toBe(false);
    });
});
