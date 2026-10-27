import { describe, expect, it } from 'vitest';
import {
    digitsToMonth,
    isMonthOnly,
    monthToDigits,
    parsePastedMonth,
} from './partial-date-input';

describe('el mes de una fecha parcial', () => {
    it('va y vuelve entre el campo y el servidor', () => {
        expect(monthToDigits('2026-05')).toBe('052026');
        expect(monthToDigits('2026-05-07')).toBe('');
        expect(digitsToMonth('052026')).toBe('2026-05');
    });

    it('no da un mes a medias ni uno que no existe', () => {
        expect(digitsToMonth('0520')).toBe('');
        expect(digitsToMonth('132026')).toBe('');
        expect(digitsToMonth('002026')).toBe('');
    });

    it('lee un mes pegado en las formas habituales', () => {
        expect(parsePastedMonth('05/2026')).toBe('2026-05');
        expect(parsePastedMonth('5/2026')).toBe('2026-05');
        expect(parsePastedMonth('05-2026')).toBe('2026-05');
        expect(parsePastedMonth('2026-05')).toBe('2026-05');
        expect(parsePastedMonth('13/2026')).toBeNull();
        expect(parsePastedMonth('06/06/2023')).toBeNull();
    });

    it('distingue un mes de una fecha completa', () => {
        expect(isMonthOnly('2026-05')).toBe(true);
        expect(isMonthOnly('2026-05-07')).toBe(false);
        expect(isMonthOnly('')).toBe(false);
    });
});
