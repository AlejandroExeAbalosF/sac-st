import { describe, expect, it } from 'vitest';
import { importeMayor } from './importe-mayor';

describe('el importe mayor dentro del cobro', () => {
    it('plegado cobra lo pendiente y no queda incompleto', () => {
        expect(importeMayor(false, '110000', '100000.00', '100000.00')).toEqual(
            {
                total: null,
                excedente: null,
                importe: '100000.00',
                incompleto: false,
            },
        );
    });

    it('abierto con un total mayor cobra ese total', () => {
        const estado = importeMayor(true, '110000', '100000.00', '100000.00');

        expect(estado.importe).toBe('110000.00');
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
            const estado = importeMayor(
                true,
                escrito,
                '100000.00',
                '100000.00',
            );

            expect(estado.incompleto).toBe(true);
            expect(estado.total).toBeNull();
            expect(estado.importe).toBe('100000.00');
        },
    );
});
