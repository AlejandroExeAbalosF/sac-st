import { describe, expect, it } from 'vitest';
import { amountFromPaste, amountOnBlur, amountToNumeric } from './amount-input';

describe('lo que recibe el campo de importe', () => {
    it('deja pasar el decimal del servidor y lo que el campo emite al escribir', () => {
        expect(amountToNumeric('2034800.00')).toBe('2034800.00');
        expect(amountToNumeric('2034800.')).toBe('2034800.');
        expect(amountToNumeric('2034800.5')).toBe('2034800.5');
        expect(amountToNumeric('1500')).toBe('1500');
    });

    it('lee un importe ya formateado con money()', () => {
        expect(amountToNumeric('2.034.800,00')).toBe('2034800.00');
        expect(amountToNumeric('1.234')).toBe('1234.00');
    });

    it('deja vacío lo vacío', () => {
        expect(amountToNumeric('')).toBe('');
        expect(amountToNumeric('  ')).toBe('');
        expect(amountToNumeric(null)).toBe('');
    });
});

describe('al salir del campo', () => {
    it('completa los centavos y conserva el decimal con punto', () => {
        expect(amountOnBlur('2034800')).toBe('2034800.00');
        expect(amountOnBlur('2034800.')).toBe('2034800.00');
        expect(amountOnBlur('2034800.5')).toBe('2034800.50');
        expect(amountOnBlur('2.034.800,5')).toBe('2034800.50');
    });

    it('deja borrar el importe entero', () => {
        expect(amountOnBlur('')).toBe('');
        expect(amountOnBlur('-')).toBe('');
    });
});

describe('lo que se pega', () => {
    it('lee los dos formatos por el último separador, como el servidor', () => {
        expect(amountFromPaste('2034800.00')).toBe('2034800.00');
        expect(amountFromPaste('2.034.800,00')).toBe('2034800.00');
        expect(amountFromPaste('$ 2.034.800,00')).toBe('2034800.00');
        expect(amountFromPaste('US$ 1.500,25')).toBe('1500.25');
        expect(amountFromPaste('1000.50')).toBe('1000.50');
    });

    it('ignora lo que no es un importe', () => {
        expect(amountFromPaste('sin importe')).toBeNull();
        expect(amountFromPaste('')).toBeNull();
    });
});
