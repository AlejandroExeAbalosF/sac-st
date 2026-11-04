import { describe, expect, it } from 'vitest';
import { importeCalculado, precioEn } from './fee-line';

type Tipo = App.Modules.Aranceles.Data.FeeTypeData;
type Precio = App.Modules.Aranceles.Data.FeeTypePriceData;

const precio = (datos: Partial<Precio>): Precio => ({
    id: 1,
    validFrom: '2019-09-02',
    unitPrice: null,
    rate: null,
    legalReference: null,
    createdBy: null,
    createdAt: '2019-09-02T00:00:00Z',
    status: 'current',
    voidReason: null,
    ...datos,
});

const tipo = (mode: 'unit' | 'percentage', prices: Precio[] = []): Tipo => ({
    id: 1,
    code: 14,
    decreeLetter: 'n',
    name: 'Prueba',
    calculationMode: mode,
    unitLabel: null,
    isActive: true,
    currentPrice: prices[0] ?? null,
    prices,
});

describe('el importe de un renglón', () => {
    it('multiplica cantidad por precio', () => {
        const p = precio({ unitPrice: '50.00' });

        expect(importeCalculado(tipo('unit'), p, '2', '')).toBe('100.00');
        expect(importeCalculado(tipo('unit'), p, '0', '')).toBeNull();
        expect(importeCalculado(tipo('unit'), p, '1,5', '')).toBeNull();
    });

    it('calcula el porcentaje redondeando al centavo como el servidor', () => {
        const p = precio({ rate: '0.0700' });
        const t = tipo('percentage');

        expect(importeCalculado(t, p, '', '100000')).toBe('70.00');
        expect(importeCalculado(t, p, '', '123456')).toBe('86.42');
        expect(importeCalculado(t, p, '', '50050')).toBe('35.04');
        expect(importeCalculado(t, p, '', '5')).toBe('0.00');
        expect(importeCalculado(t, p, '', '')).toBeNull();
    });
});

describe('el precio de una fecha', () => {
    const viejo = precio({
        id: 1,
        unitPrice: '100.00',
        validFrom: '2019-09-02',
    });
    const nuevo = precio({
        id: 2,
        unitPrice: '150.00',
        validFrom: '2026-11-01',
    });
    const anulado = precio({
        id: 3,
        unitPrice: '999.00',
        validFrom: '2026-11-02',
        status: 'voided',
    });
    const t = tipo('unit', [anulado, nuevo, viejo]);

    it('toma el último que ya empezó, sin los anulados', () => {
        expect(precioEn(t, '2026-10-31')?.id).toBe(1);
        expect(precioEn(t, '2026-11-01')?.id).toBe(2);
        expect(precioEn(t, '2026-12-01')?.id).toBe(2);
        expect(precioEn(t, '2019-01-01')).toBeNull();
    });
});
