import { describe, expect, it } from 'vitest';
import { amountInWords } from './amount-words';

describe('amountInWords', () => {
    it('lee los tramos que tienen forma propia', () => {
        expect(amountInWords('0')).toBe('cero');
        expect(amountInWords('1')).toBe('uno');
        expect(amountInWords('15')).toBe('quince');
        expect(amountInWords('21')).toBe('veintiuno');
        expect(amountInWords('31')).toBe('treinta y uno');
        expect(amountInWords('100')).toBe('cien');
        expect(amountInWords('101')).toBe('ciento uno');
        expect(amountInWords('500')).toBe('quinientos');
        expect(amountInWords('999')).toBe('novecientos noventa y nueve');
    });

    it('apocopa «uno» delante de mil y de millones', () => {
        expect(amountInWords('1000')).toBe('mil');
        expect(amountInWords('21000')).toBe('veintiún mil');
        expect(amountInWords('1000000')).toBe('un millón');
        expect(amountInWords('21000000')).toBe('veintiún millones');
    });

    /*
     * El caso que motiva todo esto: un cero de más no se nota contando
     * puntos, pero «diez millones» y «cien millones» no se parecen.
     */
    it('distingue a simple vista un cero de más', () => {
        expect(amountInWords('10000000')).toBe('diez millones');
        expect(amountInWords('100000000')).toBe('cien millones');
        expect(amountInWords('1000000')).toBe('un millón');
    });

    it('lee los importes del relevamiento', () => {
        expect(amountInWords('1204500.00')).toBe(
            'un millón doscientos cuatro mil quinientos',
        );
        expect(amountInWords('5790.06')).toBe(
            'cinco mil setecientos noventa con seis centavos',
        );
        expect(amountInWords('602250.00')).toBe(
            'seiscientos dos mil doscientos cincuenta',
        );
    });

    it('acepta el importe escrito como lo tipea el operador', () => {
        expect(amountInWords('1.204.500,50')).toBe(
            'un millón doscientos cuatro mil quinientos con cincuenta centavos',
        );
        expect(amountInWords('1000.50')).toBe('mil con cincuenta centavos');
    });

    it('dice el signo', () => {
        expect(amountInWords('-6000000.00')).toBe('menos seis millones');
    });

    it('calla cuando no hay nada que leer', () => {
        expect(amountInWords('')).toBe('');
        expect(amountInWords(null)).toBe('');
        expect(amountInWords('9999999999999')).toBe('');
    });
});
