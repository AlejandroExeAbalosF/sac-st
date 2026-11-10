import { describe, expect, it } from 'vitest';
import { importeMayorDelCobro } from './importe-mayor';

describe('el importe mayor dentro del cobro', () => {
    it('plegado cobra lo pendiente y no queda incompleto', () => {
        expect(
            importeMayorDelCobro(false, '110000', '100000.00', '100000.00'),
        ).toEqual({
            total: null,
            excedente: null,
            aCobrar: '100000.00',
            incompleto: false,
        });
    });

    it('abierto con un total mayor cobra ese total', () => {
        const estado = importeMayorDelCobro(
            true,
            '110000',
            '100000.00',
            '100000.00',
        );

        expect(estado.aCobrar).toBe('110000.00');
        expect(estado.total).toBe('110000.00');
        expect(estado.incompleto).toBe(false);
        expect(Number(estado.excedente)).toBe(10000);
    });

    it.each([
        ['vacío', ''],
        ['menor que lo que se debe', '90000'],
        ['igual a lo que se debe', '100000'],
    ])(
        'abierto con un total %s queda incompleto y no se cobra otra cosa',
        (_, escrito) => {
            const estado = importeMayorDelCobro(
                true,
                escrito,
                '100000.00',
                '100000.00',
            );

            expect(estado.incompleto).toBe(true);
            expect(estado.total).toBeNull();
            expect(estado.aCobrar).toBe('100000.00');
        },
    );
});
