import { describe, expect, it } from 'vitest';
import { fullYear } from '@/lib/two-digit-year';
import { documentYearWarning } from './document-year';

describe('el año de dos cifras', () => {
    it('toma el siglo que lo deja más cerca de la fecha', () => {
        expect(fullYear(29, 2026)).toBe(2029);
        expect(fullYear(98, 2026)).toBe(1998);
        expect(fullYear(26, 2026)).toBe(2026);
        expect(fullYear(2025, 2026)).toBe(2025);
    });
});

describe('el aviso del año', () => {
    it('avisa cuando el número no es del año de la fecha', () => {
        expect(documentYearWarning('12312/29', '2026-05-07')).toBe(
            'El número dice 2029 y la fecha es de 2026: revisá que esté bien. Se puede guardar igual.',
        );
        expect(documentYearWarning('HYS N°1025288/25', '2026-01-03')).toContain(
            'El número dice 2025',
        );
    });

    it('no avisa si coinciden, si falta la fecha o si el número no se lee', () => {
        expect(documentYearWarning('N°0649/26', '2026-05-07')).toBeNull();
        expect(documentYearWarning('N°0649/2026', '2026-05-07')).toBeNull();
        expect(documentYearWarning('N°0649/26', '')).toBeNull();
        expect(documentYearWarning('N°0649', '2026-05-07')).toBeNull();
        expect(documentYearWarning('expediente', '2026-05-07')).toBeNull();
    });
});
