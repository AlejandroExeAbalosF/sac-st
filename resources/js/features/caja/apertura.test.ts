import { describe, expect, it, vi } from 'vitest';
import {
    aperturaPendiente,
    esAnteriorALaApertura,
    nombreDeMoneda,
} from './apertura';

vi.mock('@/routes/caja/apertura', () => ({
    index: () => ({ url: '/caja/apertura' }),
}));

describe('nombreDeMoneda', () => {
    it('nombra las dos monedas en minúscula', () => {
        expect(nombreDeMoneda('ARS')).toBe('pesos');
        expect(nombreDeMoneda('USD')).toBe('dólares');
    });
});

describe('aperturaPendiente', () => {
    it('lleva a la apertura de ese libro a quien puede abrirlo', () => {
        const aviso = aperturaPendiente('USD', true);

        expect(aviso.titulo).toContain('en dólares');
        expect(aviso.href).toBe('/caja/apertura?moneda=usd');
        expect(aviso.accion).toBe('Abrir libros en dólares');
    });

    it('en pesos no anota la moneda en la dirección', () => {
        expect(aperturaPendiente('ARS', true).href).toBe('/caja/apertura');
    });

    it('a quien no puede abrir le dice a quién pedírselo, sin enlace', () => {
        const aviso = aperturaPendiente('USD', false);

        expect(aviso.href).toBeNull();
        expect(aviso.accion).toContain('administrador');
    });
});

describe('esAnteriorALaApertura', () => {
    const apertura = { date: '2026-09-24' };

    it('los días anteriores a la apertura no se operan', () => {
        expect(esAnteriorALaApertura('2026-09-23', apertura)).toBe(true);
        expect(esAnteriorALaApertura('2025-12-31', apertura)).toBe(true);
    });

    it('el día de la apertura y los siguientes sí', () => {
        expect(esAnteriorALaApertura('2026-09-24', apertura)).toBe(false);
        expect(esAnteriorALaApertura('2026-10-07', apertura)).toBe(false);
    });

    it('sin apertura lo resuelve el panel de apertura pendiente, no este', () => {
        expect(esAnteriorALaApertura('2026-09-23', null)).toBe(false);
    });
});
