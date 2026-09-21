import { describe, expect, it } from 'vitest';
import { conMoneda } from './moneda';

describe('conMoneda', () => {
    it('deja las direcciones de pesos como están', () => {
        expect(conMoneda('/caja/dia', 'ARS')).toBe('/caja/dia');
        expect(conMoneda('/caja/dia?fecha=2026-06-01', 'ARS')).toBe(
            '/caja/dia?fecha=2026-06-01',
        );
    });

    it('anota el dólar respetando la query que ya trae', () => {
        expect(conMoneda('/caja/dia', 'USD')).toBe('/caja/dia?moneda=usd');
        expect(conMoneda('/caja/dia?fecha=2026-06-01', 'USD')).toBe(
            '/caja/dia?fecha=2026-06-01&moneda=usd',
        );
    });
});
