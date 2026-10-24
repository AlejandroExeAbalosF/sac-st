import { describe, expect, it } from 'vitest';
import { digitsToIso, isoToDigits, parsePastedDate } from './date-input';

describe('una fecha pegada', () => {
    it('lee el día primero, como se escribe en Argentina', () => {
        expect(parsePastedDate('06/06/2023')).toBe('2023-06-06');
        expect(parsePastedDate('13/02/2024')).toBe('2024-02-13');
        expect(parsePastedDate('6/6/2023')).toBe('2023-06-06');
    });

    it('acepta guiones, puntos y el año de dos cifras de este siglo', () => {
        expect(parsePastedDate('06-06-2023')).toBe('2023-06-06');
        expect(parsePastedDate('06.06.2023')).toBe('2023-06-06');
        expect(parsePastedDate('6/6/23')).toBe('2023-06-06');
    });

    it('entiende el formato ISO y descarta la hora', () => {
        expect(parsePastedDate('2023-06-06')).toBe('2023-06-06');
        expect(parsePastedDate(' 06/06/2023 10:30 ')).toBe('2023-06-06');
    });

    it('no inventa una fecha que no existe ni lee lo que no es una fecha', () => {
        expect(parsePastedDate('31/02/2023')).toBeNull();
        expect(parsePastedDate('29/02/2023')).toBeNull();
        expect(parsePastedDate('29/02/2024')).toBe('2024-02-29');
        expect(parsePastedDate('13/13/2023')).toBeNull();
        expect(parsePastedDate('expediente 1234')).toBeNull();
        expect(parsePastedDate('')).toBeNull();
    });
});

describe('las cifras del campo', () => {
    it('van y vuelven del ISO', () => {
        expect(isoToDigits('2023-06-06')).toBe('06062023');
        expect(isoToDigits('')).toBe('');
        expect(digitsToIso('06062023')).toBe('2023-06-06');
    });

    it('no dan una fecha mientras faltan cifras o si no existe', () => {
        expect(digitsToIso('0606')).toBe('');
        expect(digitsToIso('31022023')).toBe('');
    });
});
